<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ToolDefinition;
use Crab\Engine\Async\Cancellation;

/**
 * Keeps a conversation inside the model's context window by summarizing its
 * older part. The most recent `keepTokens` stay verbatim; the rest becomes a
 * summary the model writes, which a later compaction folds in again.
 */
final readonly class Compactor
{
    public const INSTRUCTIONS = <<<'TEXT'
        Summarize the conversation above so that the work can continue from the summary alone. The recent messages that follow it are kept as they are, so concentrate on what came before them.

        Use these headings:
        ## Goal: what the user asked for, in their terms, and any later changes to it.
        ## Constraints and preferences: what the user required or ruled out.
        ## Done: what has been completed and verified.
        ## In progress: what was being worked on when this summary was written.
        ## Key facts: decisions taken, file paths, function names, commands, error messages, values; copy identifiers exactly.
        ## Next steps: what remains, in order.

        Be specific and complete but brief. Do not invent anything that is not in the conversation.
        TEXT;

    /**
     * @param array<string, mixed> $options Sent with the summary's request, as its reasoning effort.
     */
    public function __construct(
        private Model $model,
        private string $modelName,
        private int $contextWindow = 128_000,
        private int $reserveTokens = 16_384,
        private int $keepTokens = 20_000,
        private array $options = [],
    ) {
        if ($this->contextWindow <= $this->reserveTokens || $this->keepTokens < 1) {
            throw new \InvalidArgumentException('Compaction needs a context window larger than its reserve, and something to keep.');
        }
    }

    /**
     * An estimate of the tokens a request would take: the provider's own count
     * from the last reply since the latest compaction, plus a character-based
     * estimate of what came after it; never less than the estimate alone.
     *
     * @param list<ToolDefinition> $tools
     */
    public function tokens(Context $context, string $system, array $tools): int
    {
        $base = self::estimate($system) + intdiv(strlen((string) json_encode(array_map(static fn (ToolDefinition $tool): array => $tool->openAi(), $tools))), 4);
        $estimate = $base;
        foreach ($context->items as [, $message]) {
            $estimate += self::size($message);
        }
        $measured = null;
        foreach ($context->items as $index => [, $message]) {
            if ($index >= $context->sinceCompaction && $message->role === 'assistant' && isset($message->meta['usage']['input'])) {
                $measured = (int) $message->meta['usage']['input'] + (int) ($message->meta['usage']['output'] ?? 0);
                foreach (array_slice($context->items, $index + 1) as [, $later]) {
                    $measured += self::size($later);
                }
            }
        }
        return max($estimate, $measured ?? 0);
    }

    public function needed(int $tokens): bool
    {
        return $tokens > $this->contextWindow - $this->reserveTokens;
    }

    /**
     * Summarizes everything before the kept recent messages.
     *
     * @return Compaction|null Null when there is nothing old enough to summarize.
     */
    public function compact(Context $context, int $tokensBefore, ?Cancellation $cancellation = null): ?Compaction
    {
        $cut = $this->cut($context);
        if ($cut === null) {
            return null;
        }
        $older = array_slice($context->items, 0, $cut);
        $transcript = self::transcript(array_map(static fn (array $item): Message => $item[1], $older), ($this->contextWindow - $this->reserveTokens) * 3);
        $result = $this->model->stream(
            new ModelRequest($this->modelName, [
                Message::system('You summarize a coding session between a user and a coding agent, for the agent to continue from.'),
                Message::user("<conversation>\n{$transcript}\n</conversation>\n\n" . self::INSTRUCTIONS),
            ], [], $this->options),
            static function (): void {},
            $cancellation,
        );
        $summary = trim($result->text);
        if ($summary === '') {
            throw new \RuntimeException('The model returned an empty summary; the conversation was not compacted.');
        }
        $after = self::estimate($summary);
        foreach (array_slice($context->items, $cut) as [, $message]) {
            $after += self::size($message);
        }
        return new Compaction($summary, $context->items[$cut][0], $tokensBefore, $after);
    }

    /**
     * Where the kept part starts: keepTokens back from the end, moved to the
     * start of a stored user or assistant message so that no tool call is
     * parted from its result.
     */
    private function cut(Context $context): ?int
    {
        $items = $context->items;
        $kept = 0;
        $index = count($items);
        while ($index > 0 && $kept < $this->keepTokens) {
            $index--;
            $kept += self::size($items[$index][1]);
        }
        $boundary = static fn (int $i): bool => $items[$i][0] !== null && $items[$i][1]->role !== 'tool';
        for ($i = $index; $i > 0; $i--) {
            if ($boundary($i)) {
                return $i;
            }
        }
        // Everything fits in the kept budget, yet the window is full: keep only the latest boundary.
        for ($i = count($items) - 1; $i > 0; $i--) {
            if ($boundary($i)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * @param list<Message> $messages
     */
    private static function transcript(array $messages, int $maxBytes): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $parts[] = match ($message->role) {
                'tool' => "### Tool result ({$message->toolName}" . ($message->ok ? '' : ', failed') . ")\n" . self::clip($message->text, 2000),
                'assistant' => "### Assistant\n" . trim($message->text . "\n" . implode("\n", array_map(
                    static fn ($call): string => "[called {$call->name} " . self::clip((string) json_encode($call->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 600) . ']',
                    $message->toolCalls,
                ))),
                default => '### ' . ucfirst($message->role) . "\n" . $message->text,
            };
        }
        $text = implode("\n\n", $parts);
        if (strlen($text) > $maxBytes && $parts !== []) {
            // Shorten every message evenly first, so the start (often the goal) survives.
            $share = max(200, intdiv($maxBytes, count($parts)) - 16); // room for the separator and the clip mark
            $text = implode("\n\n", array_map(static fn (string $part): string => self::clip($part, $share), $parts));
        }
        // Still too long: drop the oldest part, which an earlier summary may already cover.
        return strlen($text) > $maxBytes ? '[…]' . mb_strcut($text, strlen($text) - $maxBytes, null, 'UTF-8') : $text;
    }

    private static function clip(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? mb_strcut($text, 0, $bytes, 'UTF-8') . ' […]' : $text;
    }

    public static function size(Message $message): int
    {
        $size = strlen($message->text) + 16;
        foreach ($message->toolCalls as $call) {
            $size += strlen($call->name) + strlen((string) json_encode($call->arguments)) + 16;
        }
        return intdiv($size, 4);
    }

    private static function estimate(string $text): int
    {
        return intdiv(strlen($text), 4);
    }
}

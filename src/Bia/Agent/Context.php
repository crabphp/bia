<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\Pruning;

/**
 * What is sent to the model, built from a session's path of entries.
 *
 * The latest compaction stands in for everything before the entry it kept
 * first. Every tool call is given a result, so a session cut off between a
 * call and its result (a crash, a kill) is never refused by the provider.
 * A pruning cuts the old tool results it names to their start and end.
 */
final readonly class Context
{
    /** A tool result longer than this, in characters, may be pruned to its first and last. */
    public const PRUNE_OVER = 8192;
    public const PRUNE_HEAD = 4096;
    public const PRUNE_TAIL = 1024;

    /**
     * @param list<array{int|null, Message}> $items Each message with the entry it came from; null for a summary or a filled-in result.
     * @param int $sinceCompaction The index of the first item stored after the latest compaction.
     */
    private function __construct(
        public array $items,
        public int $sinceCompaction,
        public ?Compaction $compaction,
    ) {}

    /**
     * @param list<Entry> $entries
     */
    public static function build(array $entries): self
    {
        $last = null;
        $pruned = [];
        foreach ($entries as $index => $entry) {
            if ($entry->payload instanceof Compaction) {
                $last = $index;
            } elseif ($entry->payload instanceof Pruning) {
                $pruned = $entry->payload->entries + $pruned;
            }
        }
        if ($pruned !== []) {
            $entries = array_map(static fn (Entry $entry): Entry => $entry->payload instanceof Message && array_key_exists($entry->id, $pruned)
                ? new Entry($entry->id, $entry->parentId, self::prune($entry->payload, $pruned[$entry->id]), $entry->createdAt)
                : $entry, $entries);
        }
        $items = [];
        $since = 0;
        $compaction = null;
        if ($last !== null) {
            $compaction = $entries[$last]->payload;
            $items[] = [null, Message::user("[The earlier part of this conversation was compacted. Summary:]\n\n" . $compaction->summary)];
            $kept = false;
            foreach (array_slice($entries, 0, $last) as $entry) {
                $kept = $kept || $entry->id === $compaction->firstKeptId;
                if ($kept && $entry->payload instanceof Message) {
                    $items[] = [$entry->id, $entry->payload];
                }
            }
            $since = count($items);
            $entries = array_slice($entries, $last + 1);
        }
        foreach ($entries as $entry) {
            if ($entry->payload instanceof Message) {
                $items[] = [$entry->id, $entry->payload];
            }
        }
        return new self(self::repair($items), $since, $compaction);
    }

    /**
     * A tool result cut to its start and end, saying where the whole of it is.
     */
    public static function prune(Message $message, ?string $path): Message
    {
        $text = $message->text;
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= self::PRUNE_HEAD + self::PRUNE_TAIL) {
            return $message;
        }
        $head = mb_substr($text, 0, self::PRUNE_HEAD, 'UTF-8');
        $tail = mb_substr($text, $length - self::PRUNE_TAIL, null, 'UTF-8');
        $cut = substr_count(mb_substr($text, self::PRUNE_HEAD, $length - self::PRUNE_HEAD - self::PRUNE_TAIL, 'UTF-8'), "\n");
        $note = "[… {$cut} lines cut later, to give the conversation room"
            . ($path !== null ? "; the whole output is saved at {$path}: page through it with read_file offset/limit" : '')
            . ' …]';
        return new Message($message->role, "{$head}\n{$note}\n{$tail}", $message->toolCalls, $message->reasoning, $message->toolCallId, $message->toolName, $message->ok, $message->meta, $message->images);
    }

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return array_map(static fn (array $item): Message => $item[1], $this->items);
    }

    /**
     * @param list<array{int|null, Message}> $items
     * @return list<array{int|null, Message}>
     */
    private static function repair(array $items): array
    {
        $repaired = [];
        $pending = [];
        $flush = static function () use (&$repaired, &$pending): void {
            foreach ($pending as $call) {
                $repaired[] = [null, Message::toolResult($call, '[no result: the turn was interrupted]', false)];
            }
            $pending = [];
        };
        foreach ($items as $item) {
            $message = $item[1];
            if ($message->role === 'tool') {
                if (!isset($pending[(string) $message->toolCallId])) {
                    continue; // A result whose call is gone, cut away by a compaction.
                }
                unset($pending[(string) $message->toolCallId]);
                $repaired[] = $item;
                continue;
            }
            $flush();
            $repaired[] = $item;
            foreach ($message->toolCalls as $call) {
                $pending[$call->id] = $call;
            }
        }
        $flush();
        return $repaired;
    }
}

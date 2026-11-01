<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Tui\Highlight;
use Bia\Tui\Markdown;
use Bia\Tui\Style;
use Bia\Tui\Surface;

/**
 * Shows a turn in the conversation's screen: answer text as it streams,
 * rendered from markdown a line at a time, a line per tool call with the
 * change it made, and what the agent is doing on the spinner.
 */
final class TuiRenderer implements Renderer
{
    /** Diff lines shown under a tool call. */
    private const DIFF_LINES = 16;

    /** @var \Closure(string): void */
    private \Closure $activity;
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $usage;
    private Markdown $markdown;
    /** The answer's line still streaming, as the model wrote it. */
    private string $line = '';

    public function __construct(private readonly Surface $region)
    {
        $this->activity = static function (string $label): void {};
        $this->usage = static function (array $usage): void {};
        $this->markdown = new Markdown();
    }

    /**
     * @param \Closure(string): void $activity Told what the agent is doing.
     * @param \Closure(array<string, mixed>): void $usage Told the token usage of each request.
     */
    public function listen(\Closure $activity, \Closure $usage): void
    {
        $this->activity = $activity;
        $this->usage = $usage;
    }

    public function event(ModelEvent $event): void
    {
        switch ($event->type) {
            case 'text_delta':
                ($this->activity)('Writing');
                $this->text($event->text);
                return;
            case 'reasoning_delta':
                ($this->activity)('Thinking');
                return;
            case 'tool_call':
                $name = is_string($event->data['name'] ?? null) ? $event->data['name'] : 'a tool';
                ($this->activity)("Running {$name}");
                return;
            case 'usage':
                ($this->usage)($event->data);
                return;
        }
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        $this->end();
        $this->lineStart();
        $this->region->print(implode('', array_map(static fn (string $line): string => $line . "\n", self::toolLines($call, $result->ok, $result->text, $result->data))));
        ($this->activity)('Thinking');
    }

    public function notice(string $message): void
    {
        $this->end();
        $this->lineStart();
        $this->region->print(Style::dim("· {$message}") . "\n");
    }

    public function finish(ModelResult $result): void
    {
        $this->end();
        $this->lineStart();
    }

    /**
     * A tool call as shown: its mark, name and subject, then the change it
     * made, or the end of a shell command's output or of an error.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function toolLines(ToolCall $call, bool $ok, string $text, array $data): array
    {
        $line = ($ok ? Style::ok('✓') : Style::error('✗')) . ' ' . $call->name;
        $subject = AnsiRenderer::subject($call->arguments);
        if ($subject !== '') {
            $line .= ' ' . Style::dim($subject);
        }
        $lines = [$line];
        $diff = $data['diff'] ?? null;
        if ($ok && is_array($diff) && $diff !== []) {
            $path = is_string($data['path'] ?? null) ? $data['path'] : (is_string($call->arguments['path'] ?? null) ? $call->arguments['path'] : '');
            array_push($lines, ...self::diffLines($diff, $path));
        } elseif (!$ok || $call->name === 'shell') {
            foreach (array_slice(preg_split('/\R/', rtrim($text)) ?: [], -3) as $output) {
                if ($output !== '') {
                    $lines[] = '  ' . Style::dim(Style::truncate(self::printable($output), 160));
                }
            }
        }
        return $lines;
    }

    /**
     * A diff as shown: added and removed lines on a green or red tint as wide
     * as the terminal, code highlighted by the file's language, context plain.
     *
     * @param array<mixed> $diff
     * @return list<string>
     */
    private static function diffLines(array $diff, string $path): array
    {
        $lines = [];
        $left = 0;
        $hunks = 0;
        $highlight = $path !== '' ? Highlight::for($path) : null;
        $tint = Style::color();
        foreach ($diff as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            if (str_starts_with($entry, '…')) {
                $left += (int) substr($entry, strlen('…'));
                continue;
            }
            $mark = $entry[0];
            $full = count($lines) >= self::DIFF_LINES;
            if ($full) {
                $left += $mark === '+' || $mark === '-' ? 1 : 0;
                continue;
            }
            $text = Style::truncate(self::printable(substr($entry, 1)), 200);
            if ($mark === '@') {
                if ($hunks++ > 0) {
                    $lines[] = Style::dim('  ⋯');
                }
                // A hunk starts afresh: a comment open before it is not known.
                $highlight = $path !== '' ? Highlight::for($path) : null;
                continue;
            }
            if ($mark === '=') {
                $lines[] = Style::dim('  ' . $text);
                $highlight = Highlight::for($text);
                $path = $text;
                continue;
            }
            $code = $highlight?->line($text) ?? $text;
            $lines[] = match (true) {
                !$tint => "  {$mark} {$text}",
                $mark === '+' => "\e[48;5;22m\e[32m  + \e[39m{$code}\e[K\e[0m",
                $mark === '-' => "\e[48;5;52m\e[31m  - \e[39m{$code}\e[K\e[0m",
                default => "    {$code}\e[0m",
            };
        }
        if ($left > 0) {
            $lines[] = Style::dim("  … {$left} more");
        }
        return $lines;
    }

    /**
     * File text made safe to show: no escape codes of its own.
     */
    private static function printable(string $text): string
    {
        return str_replace("\e", '␛', $text);
    }

    private function text(string $delta): void
    {
        if ($this->line !== '' && $this->region->atLineStart()) {
            // The draft was kept by something printed after it, as a stopped turn's note.
            $this->line = '';
            $this->markdown->reset();
        }
        $this->line .= $delta;
        while (($end = strpos($this->line, "\n")) !== false) {
            $this->region->draft('');
            $this->region->print($this->markdown->line(substr($this->line, 0, $end)) . "\n");
            $this->line = substr($this->line, $end + 1);
        }
        $this->region->draft($this->line === '' ? '' : $this->markdown->preview($this->line));
    }

    /**
     * Finishes the answer's last line, rendered, and starts afresh.
     */
    private function end(): void
    {
        if ($this->line !== '') {
            $this->region->draft('');
            $this->region->print($this->markdown->line($this->line));
            $this->line = '';
        }
        $this->markdown->reset();
    }

    private function lineStart(): void
    {
        if (!$this->region->atLineStart()) {
            $this->region->print("\n");
        }
    }
}

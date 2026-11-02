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
use Bia\Tui\Pacer;
use Bia\Tui\Style;
use Bia\Tui\Surface;
use Crab\Engine\EventLoop;

/**
 * Shows a turn in the conversation's screen: answer text as it streams,
 * rendered from markdown a line at a time, a line per tool call with the
 * change it made, and what the agent is doing on the spinner.
 */
final class TuiRenderer implements Renderer
{
    /** Diff lines shown under a tool call. */
    private const DIFF_LINES = 10;

    /** Output lines shown under a call while it runs. */
    private const LIVE_LINES = 3;

    /** Output lines shown under a tool call opened. */
    private const OPEN_LINES = 500;

    /** @var \Closure(string): void */
    private \Closure $activity;
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $usage;
    /** @var \Closure(int): void|null */
    private ?\Closure $streamed = null;
    private Markdown $markdown;
    /** Calls that worked in a row, drawn as one line until something else comes. */
    private Gathered $gathered;
    /** @var array<string, array{call: ToolCall, output: string}> Calls running, by id, with their output so far. */
    private array $running = [];
    /** How text appears: as it arrives, "normal"; paced to a steady flow, "smooth"; or paced and falling into place, "power". */
    public const MODES = ['normal', 'smooth', 'power'];

    private string $mode = 'normal';
    private Pacer $pacer;
    /** The timer that hands paced text on, a frame at a time, while some waits. */
    private ?string $ticker = null;
    private float $ticked = 0.0;

    /** The block being drawn: the answer's text, or tool calls and notes; null between turns. */
    private ?string $block = null;
    /** Whether the answer has shown nothing yet. */
    private bool $fresh = false;
    /** The answer's line still streaming, as the model wrote it. */
    private string $line = '';
    /** Whether a draft of ours is on screen: the streaming line, or a table not yet whole. */
    private bool $drafting = false;

    public function __construct(private readonly Surface $region)
    {
        $this->activity = static function (string $label): void {};
        $this->usage = static function (array $usage): void {};
        $this->markdown = new Markdown();
        $this->gathered = new Gathered();
        $this->pacer = new Pacer();
    }

    /**
     * Sets how the answer's text appears; one of MODES.
     */
    public function mode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('A render mode is one of: ' . implode(', ', self::MODES) . '.');
        }
        $this->catchUp();
        $this->mode = $mode;
    }

    public function current(): string
    {
        return $this->mode;
    }

    /**
     * Shows at once whatever paced text still waits, as before anything
     * else is drawn, and when a turn stops.
     */
    public function catchUp(): void
    {
        if ($this->ticker !== null) {
            EventLoop::current()->cancel($this->ticker);
            $this->ticker = null;
        }
        if (!$this->pacer->empty()) {
            $this->show($this->pacer->drain());
        }
    }

    /**
     * @param \Closure(string): void $activity Told what the agent is doing.
     * @param \Closure(array<string, mixed>): void $usage Told the token usage of each request.
     * @param \Closure(int): void|null $streamed Told the bytes of answer text as they arrive.
     */
    public function listen(\Closure $activity, \Closure $usage, ?\Closure $streamed = null): void
    {
        $this->activity = $activity;
        $this->usage = $usage;
        $this->streamed = $streamed;
    }

    public function event(ModelEvent $event): void
    {
        switch ($event->type) {
            case 'text_delta':
                ($this->activity)('Writing');
                if ($this->streamed !== null) {
                    ($this->streamed)(strlen($event->text));
                }
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

    public function running(ToolCall $call, string $output): void
    {
        $this->live();
        if (!isset($this->running[$call->id])) {
            $this->running[$call->id] = ['call' => $call, 'output' => ''];
        }
        // Only the end is shown while it runs; the whole is in the result.
        $this->running[$call->id]['output'] = substr($this->running[$call->id]['output'] . $output, -8192);
        $this->draw();
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        $this->live();
        unset($this->running[$call->id]);
        if (Gathered::takes($call, $result)) {
            $this->gathered->add($call, $result);
        } else {
            // A failure or a change is shown on its own, after the calls before it.
            $this->draw(); // without it among the running
            $this->settle();
            $this->begin('work');
            $this->region->fold(
                implode("\n", self::toolLines($call, $result->ok, $result->text, $result->data)),
                implode("\n", self::toolLines($call, $result->ok, $result->text, $result->data, open: true)),
            );
        }
        $this->draw();
        ($this->activity)('Thinking');
    }

    /**
     * Makes ready to draw calls: the answer before them finished, and what
     * was drawn of earlier calls forgotten if something printed after it kept it.
     */
    private function live(): void
    {
        if ($this->gathered->empty() && $this->running === []) {
            $this->end();
            $this->begin('work');
            return;
        }
        if ($this->region->atLineStart()) {
            // Kept as it was drawn, as by a stopped turn's note: start afresh.
            $this->gathered = new Gathered();
            $this->running = [];
        }
    }

    /**
     * The calls done so far as their line, then each one running with the
     * end of its output.
     */
    private function draw(): void
    {
        $lines = $this->gathered->empty() ? [] : [$this->gathered->line()];
        foreach ($this->running as ['call' => $call, 'output' => $output]) {
            $subject = AnsiRenderer::subject($call->arguments);
            $lines[] = Style::dim('· ' . $call->name . ($subject !== '' ? ' ' . $subject : ''));
            $rows = $output === '' ? [] : (preg_split('/\R/', rtrim($output)) ?: []);
            if (count($rows) > self::LIVE_LINES) {
                $lines[] = '  ' . Style::dim('…'); // more above, kept for when it is opened
            }
            foreach (array_slice($rows, -self::LIVE_LINES) as $row) {
                $lines[] = '  ' . Style::dim(Style::truncate(self::printable($row), 160));
            }
        }
        $this->region->draft(implode("\n", $lines));
    }

    public function notice(string $message): void
    {
        $this->end();
        $this->begin('work');
        $this->region->print(Style::dim("· {$message}") . "\n");
    }

    public function finish(ModelResult $result): void
    {
        $this->end();
        $this->lineStart();
        $this->block = null;
    }

    /**
     * Starts a block of this kind, a blank line from the one before; within
     * a block, nothing is spaced.
     */
    private function begin(string $kind): void
    {
        if ($this->block !== $kind) {
            $this->region->gap();
            $this->block = $kind;
            return;
        }
        $this->lineStart();
    }

    /**
     * A tool call as shown: its mark, name and subject, then the change it
     * made, or the end of a failure's output. Opened, all of the change and
     * of the output.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function toolLines(ToolCall $call, bool $ok, string $text, array $data, bool $open = false): array
    {
        // An MCP tool is shown by its server and its own name: mcp__github__search reads "github · search".
        $name = preg_match('/^mcp__(.+?)__(.+)$/', $call->name, $m) === 1 ? $m[1] . ' · ' . $m[2] : $call->name;
        $subject = AnsiRenderer::subject($call->arguments);
        // The mark says how it went; the words are secondary to the answer, so dim.
        $line = ($ok ? Style::ok('✓') : Style::error('✗')) . ' ' . Style::dim($name . ($subject !== '' ? ' ' . $subject : ''));
        $diff = $data['diff'] ?? null;
        $output = $text === '' ? [] : (preg_split('/\R/', rtrim($text)) ?: []);
        if ($ok && is_array($diff) && $diff !== []) {
            $path = is_string($data['path'] ?? null) ? $data['path'] : (is_string($call->arguments['path'] ?? null) ? $call->arguments['path'] : '');
            return [$line, ...self::diffLines($diff, $path, $open ? PHP_INT_MAX : self::DIFF_LINES)];
        }
        if (!$open) {
            if ($ok) {
                // A call that worked says so by its mark; its output is there to open.
                return [$line . ($output !== [] ? Style::dim(' · ' . count($output) . (count($output) === 1 ? ' line' : ' lines')) : '')];
            }
            $output = array_slice($output, -3); // a failure's end says why
        } elseif (count($output) > self::OPEN_LINES) {
            $left = count($output) - self::OPEN_LINES;
            $output = [...array_slice($output, 0, self::OPEN_LINES), "… {$left} more"];
        }
        $lines = [$line];
        foreach ($output as $row) {
            if ($row !== '') {
                $lines[] = '  ' . Style::dim($open ? self::printable($row) : Style::truncate(self::printable($row), 160));
            }
        }
        return $lines;
    }

    /**
     * A diff as shown: added and removed lines marked by a green + or a red -,
     * with no background, code highlighted by the file's language, context plain.
     *
     * @param array<mixed> $diff
     * @return list<string>
     */
    private static function diffLines(array $diff, string $path, int $limit): array
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
            $full = count($lines) >= $limit;
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
                $mark === '+' => "  \e[32m+\e[39m {$code}\e[0m",
                $mark === '-' => "  \e[31m-\e[39m {$code}\e[0m",
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
        if ($this->mode === 'normal') {
            $this->show($delta);
            return;
        }
        $this->pacer->push($delta);
        if ($this->ticker === null) {
            $this->ticked = microtime(true);
            $this->ticker = EventLoop::current()->repeat(1 / 60, function (): void {
                $now = microtime(true);
                $due = $this->pacer->take($now - $this->ticked);
                $this->ticked = $now;
                if ($due !== '') {
                    $this->show($due);
                    if ($this->mode === 'power') {
                        $this->region->burst($due);
                    }
                }
                if ($this->pacer->empty() && $this->ticker !== null) {
                    EventLoop::current()->cancel($this->ticker);
                    $this->ticker = null;
                }
            });
        }
    }

    /**
     * Shows text of the answer: whole lines rendered, the rest drafted.
     */
    private function show(string $delta): void
    {
        $this->settle();
        if ($this->block !== 'text') {
            $this->begin('text');
            $this->fresh = true;
        }
        if ($this->fresh) {
            // The gap is the space before an answer: its own blank lines at the start are dropped.
            $delta = ltrim($delta, "\n");
            if ($delta === '') {
                return;
            }
            $this->fresh = false;
        }
        if ($this->drafting && $this->region->atLineStart()) {
            // The draft was kept by something printed after it, as a stopped turn's note.
            $this->line = '';
            $this->markdown = new Markdown();
        }
        $this->line .= $delta;
        while (($end = strpos($this->line, "\n")) !== false) {
            $this->region->draft('');
            foreach ($this->markdown->line(substr($this->line, 0, $end)) as $done) {
                $this->region->print($done . "\n");
            }
            $this->line = substr($this->line, $end + 1);
        }
        // What is not finished: the line still streaming, and a table not yet whole.
        $preview = $this->markdown->preview($this->line);
        $this->region->draft($preview);
        $this->drafting = $preview !== '';
    }

    /**
     * Finishes the answer, rendered: its last line and a table left open, and starts afresh.
     */
    private function end(): void
    {
        $this->catchUp();
        $this->settle();
        $this->region->draft('');
        $done = $this->line !== '' ? $this->markdown->line($this->line) : [];
        array_push($done, ...$this->markdown->finish());
        if ($done !== []) {
            $this->region->print(implode("\n", $done));
        }
        $this->line = '';
        $this->markdown = new Markdown();
        $this->drafting = false;
    }

    /**
     * Prints the gathered calls' line for good, without the latest subject.
     */
    private function settle(): void
    {
        if ($this->gathered->empty() && $this->running === []) {
            return;
        }
        // Printed after, the lines are already kept as they were drawn.
        if (!$this->region->atLineStart()) {
            $this->region->draft('');
            if (!$this->gathered->empty()) {
                $this->region->fold($this->gathered->line(), $this->gathered->lines());
            }
        }
        $this->gathered = new Gathered();
        $this->running = [];
    }

    private function lineStart(): void
    {
        if (!$this->region->atLineStart()) {
            $this->region->print("\n");
        }
    }
}

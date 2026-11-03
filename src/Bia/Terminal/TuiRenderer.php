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

    /** Output lines shown under a call that worked, and under one that failed. */
    private const RESULT_LINES = 2;
    private const FAILURE_LINES = 3;

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

    /** @var list<array{from: int, to: ?int, calls: list<array{0: ToolCall, 1: ToolResult}>}> This turn's runs of tool calls, to collapse once it is done. */
    private array $runs = [];

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
        $this->markdown = new Markdown($this->region->width());
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
        if ($this->runs !== []) {
            $this->runs[array_key_last($this->runs)]['calls'][] = [$call, $result];
        }
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
     * The block explored so far, then each call running, with the end of its
     * output, as its row will be once done.
     */
    private function draw(): void
    {
        // Still exploring while a read, a listing or a search runs; a command running after says it is done.
        $exploring = array_filter($this->running, static fn (array $run): bool => in_array($run['call']->name, ['read_file', 'list_files', 'search'], true)) !== [];
        $lines = $this->gathered->empty() ? [] : [$this->gathered->closed(live: $exploring)];
        foreach ($this->running as ['call' => $call, 'output' => $output]) {
            $lines[] = self::mark(null) . ' ' . Style::dim(self::title($call, [], running: true));
            $rows = self::rows($output);
            array_push($lines, ...self::under(array_slice($rows, -self::LIVE_LINES), count($rows) - self::LIVE_LINES, false, hint: false));
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
        $this->collapse();
    }

    /**
     * Collapses each of the turn's runs of tool calls into its one line, as
     * a turn is left once done, or stopped and a new one asked.
     */
    public function collapse(): void
    {
        $this->catchUp();
        $this->settle();
        $this->closeRun();
        foreach ($this->runs as $run) {
            if ($run['calls'] !== [] && $run['to'] !== null) {
                $this->region->collapse($run['from'], $run['to'], Summary::line($run['calls']));
            }
        }
        $this->runs = [];
        $this->block = null;
    }

    /**
     * Ends the run of tool calls being drawn, where the output is now.
     */
    private function closeRun(): void
    {
        $last = array_key_last($this->runs);
        if ($last !== null && $this->runs[$last]['to'] === null) {
            $this->runs[$last]['to'] = $this->region->here();
        }
    }

    /**
     * Starts a block of this kind, a blank line from the one before; within
     * a block, nothing is spaced.
     */
    private function begin(string $kind): void
    {
        if ($this->block !== $kind) {
            if ($this->block === 'work') {
                $this->closeRun();
            }
            $this->region->gap();
            $this->block = $kind;
            if ($kind === 'work') {
                $this->runs[] = ['from' => $this->region->here(), 'to' => null, 'calls' => []];
            }
            return;
        }
        $this->lineStart();
    }

    /**
     * A tool call's row, as Codex and Claude Code show one: a dot (green
     * done, red failed, dim running) and what was done in plain words, then
     * under it, on a └, what came of it: the end of a command's output, the
     * start of a tool's, a failure's last lines, an edit's diff with its
     * line numbers. Closed, a few lines and how many more; opened, all.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function toolLines(ToolCall $call, bool $ok, string $text, array $data, bool $open = false): array
    {
        $line = self::mark($ok) . ' ' . Style::dim(self::title($call, $data));
        $diff = $data['diff'] ?? null;
        if ($ok && is_array($diff) && $diff !== []) {
            $path = is_string($data['path'] ?? null) ? $data['path'] : (is_string($call->arguments['path'] ?? null) ? $call->arguments['path'] : '');
            return [$line, ...self::diffLines($diff, $path, $open ? PHP_INT_MAX : self::DIFF_LINES)];
        }
        $rows = self::rows($text);
        if ($open) {
            $shown = array_slice($rows, 0, self::OPEN_LINES);
            return [$line, ...self::under($shown, count($rows) - count($shown), true, hint: false)];
        }
        if ($rows === []) {
            return [$line, '  └ ' . Style::dim('(no output)')];
        }
        // A command's end says how it went, as does a failure's; another tool's start says what it gave.
        $fromEnd = !$ok || $call->name === 'shell';
        $count = $ok ? self::RESULT_LINES : self::FAILURE_LINES;
        $shown = $fromEnd ? array_slice($rows, -$count) : array_slice($rows, 0, $count);
        return [$line, ...self::under($shown, count($rows) - count($shown), $fromEnd ? false : true)];
    }

    /**
     * A tool call's dot: green done, red failed, dim while it runs.
     */
    public static function mark(?bool $ok): string
    {
        return match ($ok) {
            true => Style::ok('•'),
            false => Style::error('•'),
            null => Style::dim('•'),
        };
    }

    /**
     * What a call did, in plain words: "Ran composer test", "Edited
     * src/App.php (+6 −2)".
     *
     * @param array<string, mixed> $data
     */
    private static function title(ToolCall $call, array $data, bool $running = false): string
    {
        $arguments = $call->arguments;
        $path = (string) ($arguments['path'] ?? '');
        $command = (string) ($arguments['command'] ?? '');
        $firstLine = strtok($command, "\n") ?: '';
        $command = $firstLine . ($firstLine !== rtrim($command) ? ' …' : '');
        $title = match (true) {
            $call->name === 'shell' && ($arguments['background'] ?? false) === true => 'Started ' . $command . ' in the background',
            $call->name === 'shell' => ($running ? 'Running ' : 'Ran ') . $command
                . (is_string($arguments['workdir'] ?? null) && !in_array($arguments['workdir'], ['', '.'], true) ? ' in ' . $arguments['workdir'] : ''),
            $call->name === 'shell_output' => (($arguments['kill'] ?? false) === true ? 'Stopped' : 'Read') . ' background shell ' . ($arguments['id'] ?? ''),
            $call->name === 'read_file' => ($running ? 'Reading ' : 'Read ') . $path,
            $call->name === 'list_files' => ($running ? 'Listing ' : 'Listed ') . ($path !== '' ? $path : '.'),
            $call->name === 'search' => ($running ? 'Searching ' : 'Searched ') . '"' . ($arguments['pattern'] ?? '') . '"' . ($path !== '' && $path !== '.' ? " in {$path}" : ''),
            $call->name === 'edit' => ($running ? 'Editing ' : 'Edited ') . $path,
            $call->name === 'write_file' => ($running ? 'Writing ' : 'Wrote ') . $path,
            $call->name === 'apply_patch' => ($running ? 'Patching' : 'Patched') . self::patched($data),
            $call->name === 'git_diff' => $running ? 'Reading the diff' : 'Read the diff',
            $call->name === 'skill' => 'Used the skill ' . ($arguments['name'] ?? ''),
            preg_match('/^mcp__(.+?)__(.+)$/', $call->name, $m) === 1 => "Called {$m[1]} · {$m[2]}" . self::subjectOf($arguments),
            default => $call->name . self::subjectOf($arguments),
        };
        $diff = $data['diff'] ?? null;
        if (is_array($diff) && $diff !== []) {
            $title .= ' ' . self::counts($diff);
        }
        return Style::truncate(self::printable($title), 160);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function subjectOf(array $arguments): string
    {
        $subject = AnsiRenderer::subject($arguments);
        return $subject !== '' ? ' ' . $subject : '';
    }

    /**
     * The files a patch named, from its diff.
     *
     * @param array<string, mixed> $data
     */
    private static function patched(array $data): string
    {
        $files = array_values(array_map(
            static fn (string $entry): string => substr($entry, 1),
            array_filter((array) ($data['diff'] ?? []), static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, '=')),
        ));
        return $files === [] ? '' : ' ' . implode(', ', array_slice($files, 0, 3)) . (count($files) > 3 ? ' +' . (count($files) - 3) . ' more' : '');
    }

    /**
     * A diff's lines added and removed, as "(+6 −2)".
     *
     * @param array<mixed> $diff
     */
    private static function counts(array $diff): string
    {
        $added = $removed = 0;
        foreach ($diff as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            if ($entry[0] === '+') {
                $added++;
            } elseif ($entry[0] === '-') {
                $removed++;
            } elseif (str_starts_with($entry, '…')) {
                $added += (int) substr($entry, strlen('…')); // left out of the diff: counted as changed
            }
        }
        return '(+' . $added . ' −' . $removed . ')';
    }

    /**
     * Output's lines, without the blank ones at its ends.
     *
     * @return list<string>
     */
    private static function rows(string $text): array
    {
        $text = trim($text, "\r\n");
        return $text === '' ? [] : (preg_split('/\R/', $text) ?: []);
    }

    /**
     * Lines under a call's title: the first after a └, the rest set in
     * under it, dim; with how many more there are, after them or before.
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private static function under(array $rows, int $more, bool $moreAfter, bool $hint = true): array
    {
        $more = max(0, $more);
        $note = $more > 0 ? '… +' . $more . ($more === 1 ? ' line' : ' lines') . ($hint ? ' (ctrl+o to expand)' : '') : null;
        $items = array_map(static fn (string $row): string => Style::truncate(self::printable($row), 160), $rows);
        if ($note !== null) {
            $moreAfter ? $items[] = $note : array_unshift($items, $note);
        }
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = ($index === 0 ? '  └ ' : '    ') . Style::dim($item);
        }
        return $lines;
    }

    /**
     * A diff under its call's title: each line numbered, added lines after
     * a green + on dark green, removed after a red − on dark red, to the
     * row's end, highlighted by the file's language; context plain.
     *
     * @param array<mixed> $diff
     * @return list<string>
     */
    private static function diffLines(array $diff, string $path, int $limit): array
    {
        $lines = [];
        $left = 0;
        $number = 0;
        // A patch's hunks start at their line in the new file; a file tool's at its line in the old one,
        // which lines added and removed before move on.
        $old = array_filter($diff, static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, '=')) === [];
        $moved = 0;
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
            if ($mark === '@') {
                $number = (int) substr($entry, 1) + ($old ? $moved : 0);
                if ($lines !== [] && count($lines) < $limit) {
                    $lines[] = Style::dim('   ⋮');
                }
                // A hunk starts afresh: a comment open before it is not known.
                $highlight = $path !== '' ? Highlight::for($path) : null;
                continue;
            }
            $text = Style::truncate(self::printable(substr($entry, 1)), 200);
            if ($mark === '=') {
                if (count($lines) < $limit) {
                    $lines[] = Style::dim($text);
                }
                $highlight = Highlight::for($text);
                $path = $text;
                continue;
            }
            $shown = $number;
            if ($mark !== '-') {
                $number++;
            }
            $moved += $mark === '+' ? 1 : ($mark === '-' ? -1 : 0);
            if (count($lines) >= $limit) {
                $left += $mark === '+' || $mark === '-' ? 1 : 0;
                continue;
            }
            $code = $highlight?->line($text) ?? $text;
            $gutter = Style::dim(str_pad((string) $shown, 4, ' ', STR_PAD_LEFT));
            $lines[] = $gutter . match (true) {
                !$tint => " {$mark} {$text}",
                // From the mark to the row's end on a dark green or red: highlighting resets only the foreground.
                $mark === '+' => "\e[48;5;22m \e[32m+\e[39m {$code}\e[K\e[0m",
                $mark === '-' => "\e[48;5;52m \e[31m−\e[39m {$code}\e[K\e[0m",
                default => "   {$code}\e[0m",
            };
        }
        if ($left > 0) {
            $lines[] = Style::dim("… +{$left} lines (ctrl+o to expand)");
        }
        // The first line hangs from the title on a └, as every result does; the rest are set in under it.
        foreach ($lines as $index => $line) {
            $lines[$index] = ($index === 0 ? '  └ ' : '    ') . $line;
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
            $this->markdown = new Markdown($this->region->width());
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
        $this->markdown = new Markdown($this->region->width());
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
                $this->region->fold($this->gathered->closed(), $this->gathered->lines());
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

<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Tui\Style;

/**
 * Reads, listings and searches in a row, shown as one block, as Codex
 * does: "• Explored", then what was read, listed and searched, a line for
 * each kind. Opened, each call with its whole output. Anything else (a
 * command, an edit, a failure) is a row of its own.
 */
final class Gathered
{
    /** The tools that explore, and the verb each is said with. */
    private const VERBS = [
        'read_file' => 'Read',
        'list_files' => 'Listed',
        'search' => 'Searched',
    ];

    /** Lines of what was explored, closed, before the rest is counted. */
    private const LINES = 4;

    /** @var list<array{0: ToolCall, 1: ToolResult}> */
    private array $calls = [];

    public static function takes(ToolCall $call, ToolResult $result): bool
    {
        return $result->ok && isset(self::VERBS[$call->name]);
    }

    public function add(ToolCall $call, ToolResult $result): void
    {
        $this->calls[] = [$call, $result];
    }

    public function empty(): bool
    {
        return $this->calls === [];
    }

    /**
     * The block closed: its title, then a line for each kind of call, in the
     * order they came: the files read, the places listed, each search.
     *
     * @param bool $live Still exploring, so the title says so.
     */
    public function closed(bool $live = false): string
    {
        $kinds = [];
        foreach ($this->calls as [$call]) {
            $arguments = $call->arguments;
            $kinds[$call->name] ??= [];
            $kinds[$call->name][] = match ($call->name) {
                'read_file' => basename((string) ($arguments['path'] ?? '')),
                'list_files' => (string) ($arguments['path'] ?? '.'),
                default => self::search($arguments),
            };
        }
        $rows = [];
        foreach ($kinds as $name => $subjects) {
            $subjects = array_values(array_unique(array_filter($subjects, static fn (string $s): bool => $s !== '')));
            if ($name === 'search') {
                foreach ($subjects as $subject) {
                    $rows[] = 'Searched ' . $subject;
                }
                continue;
            }
            $rows[] = self::VERBS[$name] . ' ' . self::names($subjects);
        }
        if (count($rows) > self::LINES) {
            $left = count($rows) - self::LINES + 1;
            $rows = [...array_slice($rows, 0, self::LINES - 1), "… +{$left} more"];
        }
        $lines = [TuiRenderer::mark($live ? null : true) . ' ' . Style::dim($live ? 'Exploring' : 'Explored')];
        foreach ($rows as $index => $row) {
            $lines[] = ($index === 0 ? '  └ ' : '    ') . Style::dim(Style::truncate(str_replace("\e", '␛', $row), 120));
        }
        return implode("\n", $lines);
    }

    /**
     * The block opened: each call with its whole output.
     */
    public function lines(): string
    {
        $lines = [];
        foreach ($this->calls as [$call, $result]) {
            array_push($lines, ...TuiRenderer::toolLines($call, true, $result->text, $result->data, open: true));
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function search(array $arguments): string
    {
        $pattern = (string) ($arguments['pattern'] ?? '');
        $path = (string) ($arguments['path'] ?? '');
        return $pattern === '' ? '' : '"' . $pattern . '"' . ($path !== '' && $path !== '.' ? ' in ' . $path : '');
    }

    /**
     * Names joined, as many as read well, then how many more.
     *
     * @param list<string> $names
     */
    private static function names(array $names): string
    {
        $shown = array_slice($names, 0, 5);
        $left = count($names) - count($shown);
        return implode(', ', $shown) . ($left > 0 ? " +{$left} more" : '');
    }
}

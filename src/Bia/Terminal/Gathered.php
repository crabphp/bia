<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Tui\Style;

/**
 * Tool calls that worked, in a row, shown as one line once done: "✓ ran 3
 * commands, read 7 files". Opened, each call with its whole output. A call
 * that failed, or changed a file, is shown on its own, with its error or
 * its diff.
 */
final class Gathered
{
    /** What a call is counted as: a verb, then the noun for one and for more. */
    private const KINDS = [
        'shell' => ['ran', 'command', 'commands'],
        'read_file' => ['read', 'file', 'files'],
        'search' => ['searched', '', ''],
        'list_files' => ['listed', '', ''],
    ];

    /** Any other tool, as an MCP server's. */
    private const OTHER = ['used', 'tool', 'tools'];

    /** @var list<array{0: ToolCall, 1: ToolResult}> */
    private array $calls = [];

    public static function takes(ToolCall $call, ToolResult $result): bool
    {
        $diff = $result->data['diff'] ?? null;
        return $result->ok && !(is_array($diff) && $diff !== []);
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
     * The line: the calls as counts, in the order they came, even one
     * ("Ran 1 command"); which ones, and what they printed, it opens to.
     */
    public function line(): string
    {
        $counts = [];
        foreach ($this->calls as [$call]) {
            $kind = isset(self::KINDS[$call->name]) ? $call->name : '';
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $kind => $count) {
            [$verb, $one, $more] = self::KINDS[$kind] ?? self::OTHER;
            $parts[] = rtrim("{$verb} {$count} " . ($count === 1 ? $one : $more));
        }
        return Style::ok('✓') . ' ' . Style::dim(ucfirst(implode(', ', $parts)));
    }

    /**
     * Each call opened: its line and its whole output.
     */
    public function lines(): string
    {
        $lines = [];
        foreach ($this->calls as [$call, $result]) {
            array_push($lines, ...TuiRenderer::toolLines($call, true, $result->text, $result->data, open: true));
        }
        return implode("\n", $lines);
    }
}

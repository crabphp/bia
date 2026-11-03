<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Tui\Style;

/**
 * A finished run of tool calls as one line, as it is left once its turn is
 * done: "• Ran 4 commands, edited 2 files (+8 −3), read 3 files · 1 failed",
 * the dot green, or red when a call failed.
 */
final class Summary
{
    /**
     * @param list<array{0: ToolCall, 1: ToolResult}> $calls
     */
    public static function line(array $calls): string
    {
        $commands = $shells = $searches = $lists = $tools = $failed = $added = $removed = 0;
        $read = $edited = $skills = [];
        $diff = false;
        foreach ($calls as [$call, $result]) {
            if (!$result->ok) {
                $failed++;
            }
            $path = is_string($call->arguments['path'] ?? null) ? $call->arguments['path'] : '';
            switch ($call->name) {
                case 'shell':
                    $commands++;
                    break;
                case 'shell_output':
                    $shells++;
                    break;
                case 'read_file':
                    $read[$path] = true;
                    break;
                case 'search':
                    $searches++;
                    break;
                case 'list_files':
                    $lists++;
                    break;
                case 'git_diff':
                    $diff = true;
                    break;
                case 'skill':
                    $skills[(string) ($call->arguments['name'] ?? '')] = true;
                    break;
                case 'edit':
                case 'write_file':
                case 'apply_patch':
                    if (!$result->ok) {
                        break;
                    }
                    $changes = is_array($result->data['diff'] ?? null) ? $result->data['diff'] : [];
                    $files = [];
                    foreach ($changes as $entry) {
                        if (!is_string($entry) || $entry === '') {
                            continue;
                        }
                        match (true) {
                            $entry[0] === '+' => $added++,
                            $entry[0] === '-' => $removed++,
                            $entry[0] === '=' => $files[] = substr($entry, 1),
                            str_starts_with($entry, '…') => $added += (int) substr($entry, strlen('…')),
                            default => null,
                        };
                    }
                    foreach ($files !== [] ? $files : [$path !== '' ? $path : $call->id] as $file) {
                        $edited[$file] = true;
                    }
                    break;
                default:
                    $tools++;
            }
        }
        $parts = array_filter([
            $commands > 0 ? 'ran ' . self::count($commands, 'command') : '',
            $edited !== [] ? 'edited ' . self::count(count($edited), 'file') . ($added + $removed > 0 ? " (+{$added} −{$removed})" : '') : '',
            $read !== [] ? 'read ' . self::count(count($read), 'file') : '',
            $searches > 0 ? 'searched ' . ($searches === 1 ? 'once' : "{$searches} times") : '',
            $lists > 0 ? 'listed ' . self::count($lists, 'folder') : '',
            $diff ? 'read the diff' : '',
            $shells > 0 ? 'checked ' . self::count($shells, 'background shell') : '',
            count($skills) === 1 ? 'used the skill ' . array_key_first($skills) : (count($skills) > 1 ? 'used ' . count($skills) . ' skills' : ''),
            $tools > 0 ? 'called ' . self::count($tools, 'tool') : '',
        ]);
        $text = ucfirst(implode(', ', $parts) ?: 'Called ' . self::count(count($calls), 'tool'));
        if ($failed > 0) {
            $text .= ' · ' . $failed . ' failed';
        }
        return TuiRenderer::mark($failed === 0) . ' ' . Style::dim(str_replace("\e", '␛', $text));
    }

    private static function count(int $count, string $noun): string
    {
        return $count . ' ' . $noun . ($count === 1 ? '' : 's');
    }
}

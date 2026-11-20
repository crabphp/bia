<?php

declare(strict_types=1);

namespace Bia\Share;

use Bia\Contracts\Message;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;

/** A deliberately small display protocol, never a remote tool-execution protocol. */
final class Records
{
    public static function clean(string $text, int $limit = 8000): string
    {
        $text = preg_replace('/[\x00-\x08\x0b-\x1f\x7f\x{0080}-\x{009f}\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', '', mb_scrub($text, 'UTF-8')) ?? '';
        return strlen($text) > $limit ? mb_strcut($text, 0, $limit, 'UTF-8') . "\n… [truncated for sharing]" : $text;
    }

    public static function call(ToolCall $call): array
    {
        $arguments = json_encode($call->arguments, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['id' => self::clean($call->id, 128), 'name' => self::clean($call->name, 128),
            'arguments' => strlen($arguments) <= 3000 ? (json_decode(self::clean($arguments, 3000), true) ?? []) : ['_sharing' => 'Arguments truncated for sharing']];
    }

    /** Tool display only: never export arbitrary result metadata. Stay inside Session's record limit. */
    public static function tool(ToolCall $call, ToolResult $result): array
    {
        $record = ['type' => 'tool', 'call' => self::call($call), 'text' => self::clean($result->text),
            'ok' => $result->ok, 'data' => self::displayData($result->data)];
        // Quotes and backslashes can double text's wire size; leave room for arguments and the diff.
        if (strlen(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 18000) {
            $record['text'] = self::clean($result->text, 4000);
        }
        return $record;
    }

    /** Running tool output, bounded to stay inside Session's record limit after JSON escaping. */
    public static function running(ToolCall $call, string $output): array
    {
        $record = ['type' => 'running', 'call' => self::call($call), 'text' => self::clean($output)];
        if (strlen(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 18000) {
            $record['text'] = self::clean($output, 4000);
        }
        return $record;
    }

    /** A small allowlist, sanitized both before sending and before rendering untrusted records. */
    private static function displayData(array $data): array
    {
        $shown = [];
        if (is_string($data['path'] ?? null)) { $shown['path'] = self::clean($data['path'], 512); }
        if (!is_array($data['diff'] ?? null)) { return $shown; }
        $shown['diff'] = [];
        $truncated = ($data['diff_truncated'] ?? false) === true;
        $bytes = strlen(json_encode($shown, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $count = 0;
        foreach ($data['diff'] as $line) {
            if (++$count > 120) { $truncated = true; break; }
            if (!is_string($line) || !preg_match('/^(?:[ +\-=]|@\d{1,9}$|…\d{1,9}$)/u', $line)) { continue; }
            $line = str_replace("\n", ' ', self::clean($line, 4000));
            $bytes += strlen(json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;
            if ($bytes > 3900) { $truncated = true; break; }
            $shown['diff'][] = $line;
        }
        if ($truncated) { $shown['diff_truncated'] = true; }
        return $shown;
    }

    /** Render only an allowlist. Invalid and unknown records do nothing. */
    public static function play(array $record, Renderer $renderer, \Closure $user): void
    {
        $type = $record['type'] ?? null;
        $text = is_string($record['text'] ?? null) ? self::clean($record['text']) : '';
        if ($type === 'user') { $user($text); }
        elseif ($type === 'text') { $renderer->event(ModelEvent::text($text)); }
        elseif ($type === 'note') { $renderer->notice($text); }
        elseif ($type === 'finish') { $renderer->finish(new ModelResult('')); }
        elseif (in_array($type, ['tool', 'running'], true)) {
            $c = $record['call'] ?? null;
            if (!is_array($c) || !is_string($c['id'] ?? null) || !is_string($c['name'] ?? null) || !is_array($c['arguments'] ?? null)) { return; }
            // Sanitize strings recursively, including nested tool arguments that the renderer may display.
            $sanitize = function (mixed $value) use (&$sanitize): mixed {
                if (is_string($value)) { return self::clean($value, 3000); }
                if (!is_array($value)) { return $value; }
                $clean = [];
                foreach ($value as $key => $item) {
                    $clean[is_string($key) ? self::clean($key, 3000) : $key] = $sanitize($item);
                }
                return $clean;
            };
            $call = new ToolCall(self::clean($c['id'], 128), self::clean($c['name'], 128), $sanitize($c['arguments']));
            if ($type === 'running') { $renderer->running($call, $text); }
            else {
                $data = self::displayData(is_array($record['data'] ?? null) ? $record['data'] : []);
                $renderer->tool($call, new ToolResult($text, ($record['ok'] ?? false) === true, $data));
                if ($data['diff_truncated'] ?? false) { $renderer->notice('Diff truncated for sharing'); }
            }
        }
    }

    /** Last 40 completed entries; no system prompts, reasoning, metadata, images, or saved memory. */
    public static function history(array $entries): array
    {
        $records = [['type' => 'note', 'text' => 'Shared history · last 40 entries · large outputs truncated · no attachments']];
        $calls = [];
        foreach (array_slice($entries, -40) as $entry) {
            $m = $entry->message();
            if (!$m instanceof Message) { continue; }
            if ($m->role === 'user') { $records[] = ['type' => 'user', 'text' => self::clean($m->text)]; }
            elseif ($m->role === 'assistant') {
                if ($m->text !== '') {
                    $records[] = ['type' => 'text', 'text' => self::clean($m->text)];
                    $records[] = ['type' => 'finish'];
                }
                foreach ($m->toolCalls as $call) { $calls[$call->id] = $call; }
            } elseif ($m->role === 'tool') {
                $records[] = self::tool($calls[$m->toolCallId] ?? new ToolCall($m->toolCallId ?? '', $m->toolName ?? 'tool', []),
                    new ToolResult($m->text, $m->ok, is_array($m->meta['data'] ?? null) ? $m->meta['data'] : []));
            }
        }
        $records[] = ['type' => 'finish'];
        return $records;
    }
}

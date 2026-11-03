<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Contracts\Compaction;
use Bia\Contracts\Message;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolDefinition;
use Crab\Engine\Async\CancelledException;

/**
 * What the last request to the model was, for `/inspect`: the model and
 * options asked for, where its tokens went, what came back and how long it
 * took, and everything that happened on the way (retries, another model
 * answering, an option dropped, a compaction). Nothing is hidden.
 *
 * Kept in memory only, and only the latest request and its turn.
 */
final class Inspection
{
    /** @var array<string, mixed>|null The latest request. */
    private ?array $last = null;

    /** @var array{requests: int, input: int, output: int} The turn the latest request belongs to. */
    private array $turn = ['requests' => 0, 'input' => 0, 'output' => 0];

    /** @var array{before: int, after: int, summarized: int}|null A compaction made before the next request. */
    private ?array $compaction = null;

    /** @var list<string> What happened before the next request, as an option dropped while summarizing. */
    private array $pending = [];

    /** @var (\Closure(): float) */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /** A new turn begins: its counts start again. */
    public function turn(): void
    {
        $this->turn = ['requests' => 0, 'input' => 0, 'output' => 0];
    }

    /**
     * Older messages were summarized, before the next request.
     */
    public function compacted(Compaction $compaction, int $summarized): void
    {
        $this->compaction = ['before' => $compaction->tokensBefore, 'after' => $compaction->tokensAfter, 'summarized' => $summarized];
    }

    /**
     * A request is sent: what it carries, estimated by the compactor's measure.
     *
     * @param array<string, mixed> $options
     * @param list<ToolDefinition> $tools
     * @param list<Message> $messages Without the system prompt.
     */
    public function request(string $model, array $options, string $system, array $tools, array $messages): void
    {
        $parts = [
            'system' => ['tokens' => intdiv(strlen($system), 4), 'count' => $system === '' ? 0 : 1],
            'tools' => [
                'tokens' => intdiv(strlen((string) json_encode(array_map(static fn (ToolDefinition $tool): array => $tool->openAi(), $tools))), 4),
                'count' => count($tools),
            ],
            'messages' => ['tokens' => 0, 'count' => 0],
            'tool results' => ['tokens' => 0, 'count' => 0],
        ];
        foreach ($messages as $message) {
            $part = $message->role === 'tool' ? 'tool results' : 'messages';
            $parts[$part]['tokens'] += Compactor::size($message);
            $parts[$part]['count']++;
        }
        $this->last = [
            'model' => $model,
            'options' => $options,
            'parts' => $parts,
            'started' => $this->now(),
            'first' => null,
            'ended' => null,
            'events' => $this->pending,
            'compaction' => $this->compaction,
            'result' => null,
            'error' => null,
        ];
        $this->pending = [];
        $this->compaction = null;
        $this->turn['requests']++;
    }

    /** The first of the answer arrived. */
    public function firstToken(): void
    {
        if ($this->last !== null && $this->last['first'] === null) {
            $this->last['first'] = $this->now();
        }
    }

    /**
     * Something happened on the way: a retry, another model answering, an
     * option dropped. Before any request, it waits for the next one.
     */
    public function event(string $text): void
    {
        if ($this->last === null || $this->last['ended'] !== null) {
            $this->pending[] = $text;
            return;
        }
        $this->last['events'][] = $text;
    }

    public function answered(ModelResult $result): void
    {
        if ($this->last === null) {
            return;
        }
        $this->last['ended'] = $this->now();
        $this->last['result'] = [
            'finish' => $result->finishReason,
            'usage' => $result->usage,
            'calls' => count($result->toolCalls),
            'by' => $result->model,
        ];
        $this->turn['input'] += (int) ($result->usage['input'] ?? 0);
        $this->turn['output'] += (int) ($result->usage['output'] ?? 0);
    }

    public function failed(\Throwable $error): void
    {
        if ($this->last === null) {
            return;
        }
        $this->last['ended'] = $this->now();
        $this->last['error'] = $error instanceof CancelledException ? 'stopped' : ($error->getMessage() !== '' ? $error->getMessage() : $error::class);
    }

    /**
     * The latest request as rows of a label and its text, plain; null
     * before the first.
     *
     * @return array{title: string, rows: list<array{string, string}>}|null
     */
    public function report(): ?array
    {
        $last = $this->last;
        if ($last === null) {
            return null;
        }
        $now = $this->now();
        $rows = [];

        $model = $last['model'];
        foreach ($last['options'] as $key => $value) {
            $model .= ' · ' . ($key === 'reasoning_effort' ? 'effort' : $key) . ' ' . (is_scalar($value) ? (string) $value : json_encode($value));
        }
        if (($last['result']['by'] ?? '') !== '') {
            $model .= ' · answered by ' . $last['result']['by'];
        }
        $rows[] = ['Model', $model];

        $total = array_sum(array_column($last['parts'], 'tokens'));
        $sent = '≈' . self::tokens($total) . ' tokens';
        foreach ($last['parts'] as $name => $part) {
            if ($part['count'] > 0) {
                $sent .= " · {$name} " . self::tokens($part['tokens']) . ($name === 'system' ? '' : " ({$part['count']})");
            }
        }
        $rows[] = ['Sent', $sent];

        if ($last['compaction'] !== null) {
            $c = $last['compaction'];
            $rows[] = ['Compacted', self::tokens($c['before']) . ' → ' . self::tokens($c['after']) . " tokens · {$c['summarized']} "
                . ($c['summarized'] === 1 ? 'message' : 'messages') . ' summarized'];
        }

        if ($last['result'] !== null) {
            $usage = $last['result']['usage'];
            if (isset($usage['input'])) {
                $line = 'in ' . number_format((int) $usage['input']);
                if (isset($usage['cache_read'])) {
                    $line .= ' · cached ' . number_format((int) $usage['cache_read'])
                        . ($usage['input'] > 0 ? ' (' . (int) round($usage['cache_read'] * 100 / $usage['input']) . '%)' : '');
                }
                $line .= ' · out ' . number_format((int) ($usage['output'] ?? 0));
                $rows[] = ['Usage', $line];
            } else {
                $rows[] = ['Usage', 'not reported'];
            }
            $calls = $last['result']['calls'];
            $rows[] = ['Reply', $last['result']['finish'] . ($calls > 0 ? " · {$calls} tool " . ($calls === 1 ? 'call' : 'calls') : '')];
        } elseif ($last['error'] === 'stopped') {
            $rows[] = ['Reply', 'stopped'];
        } elseif ($last['error'] !== null) {
            $rows[] = ['Failed', $last['error']];
        } else {
            $rows[] = ['Reply', 'waiting'];
        }

        $end = $last['ended'] ?? $now;
        $time = $last['first'] !== null ? 'first token ' . self::seconds($last['first'] - $last['started']) . ' · ' : '';
        $rows[] = ['Time', $time . self::seconds($end - $last['started']) . ($last['ended'] === null ? ' so far' : ' in all')];

        foreach ($last['events'] as $event) {
            $rows[] = ['Event', $event];
        }

        if ($this->turn['requests'] > 1) {
            $rows[] = ['Turn', $this->turn['requests'] . ' requests · in ' . self::tokens($this->turn['input']) . ' · out ' . self::tokens($this->turn['output'])];
        }

        $ago = $now - ($last['ended'] ?? $now);
        $title = $last['ended'] === null ? 'Request running' : 'Last request · ' . ($ago < 1 ? 'just now' : self::seconds($ago) . ' ago');
        return ['title' => $title, 'rows' => $rows];
    }

    private function now(): float
    {
        return ($this->clock)();
    }

    private static function tokens(int $tokens): string
    {
        return match (true) {
            $tokens >= 10_000 => round($tokens / 1000) . 'k',
            $tokens >= 1000 => number_format($tokens / 1000, 1) . 'k',
            default => (string) $tokens,
        };
    }

    private static function seconds(float $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => floor($seconds / 3600) . 'h ' . floor(fmod($seconds, 3600) / 60) . 'm',
            $seconds >= 60 => floor($seconds / 60) . 'm ' . floor(fmod($seconds, 60)) . 's',
            $seconds >= 10 => round($seconds) . 's',
            default => number_format($seconds, 1) . 's',
        };
    }
}

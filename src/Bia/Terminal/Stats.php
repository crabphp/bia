<?php

declare(strict_types=1);

namespace Bia\Terminal;

/**
 * What /stats shows: how fast Bia draws, how fast the model answers, the
 * tokens of the session, and Bia's memory. It only counts as things happen;
 * reading it costs a few arithmetic operations and one small /proc read.
 */
final class Stats
{
    /** Characters a token is taken to be, until the provider says how many there were. */
    private const CHARS_PER_TOKEN = 4;

    private ?float $turnStart = null;
    private ?float $firstText = null;
    private ?float $lastText = null;
    private int $streamed = 0;
    /** Output tokens of the turn the provider reported, exact. */
    private int $reported = 0;
    private int $input = 0;
    private int $output = 0;

    public function turnStarted(?float $now = null): void
    {
        $this->turnStart = $now ?? microtime(true);
        $this->firstText = $this->lastText = null;
        $this->streamed = $this->reported = 0;
    }

    /**
     * Answer text arrived: its length in bytes.
     */
    public function streamed(int $bytes, ?float $now = null): void
    {
        $now ??= microtime(true);
        $this->firstText ??= $now;
        $this->lastText = $now;
        $this->streamed += $bytes;
    }

    /**
     * A request's usage, as the provider reported it.
     *
     * @param array<string, mixed> $usage
     */
    public function usage(array $usage): void
    {
        $this->input += (int) ($usage['input'] ?? 0);
        $output = (int) ($usage['output'] ?? 0);
        $this->output += $output;
        $this->reported += $output;
    }

    /**
     * The lines to show, short, the widest first.
     *
     * @param array{fps: int, frame: float} $drawing
     * @return list<string>
     */
    public function lines(array $drawing, ?int $memory = null): array
    {
        $lines = ["{$drawing['fps']} fps · " . number_format($drawing['frame'], 1) . ' ms'];
        if ($this->turnStart !== null) {
            $first = $this->firstText === null ? '…' : (int) round(($this->firstText - $this->turnStart) * 1000) . ' ms';
            $lines[] = "first token {$first} · " . $this->speed() . ' tok/s';
        }
        $lines[] = 'in ' . self::count($this->input) . ' · out ' . self::count($this->output);
        $lines[] = 'mem ' . (int) round(($memory ?? self::memory()) / 1048576) . ' MB';
        return $lines;
    }

    /**
     * Output tokens a second over the turn's streaming: exact once reported,
     * estimated from the text until then.
     */
    private function speed(): string
    {
        if ($this->firstText === null || $this->lastText === null || $this->lastText - $this->firstText < 0.05) {
            return '–';
        }
        $tokens = $this->reported > 0 ? $this->reported : $this->streamed / self::CHARS_PER_TOKEN;
        return (string) (int) round($tokens / ($this->lastText - $this->firstText));
    }

    private static function count(int $tokens): string
    {
        return $tokens >= 1000 ? number_format($tokens / 1000, 1) . 'k' : (string) $tokens;
    }

    /**
     * Bia's resident memory, as the system counts it; PHP's own count where there is no /proc.
     */
    private static function memory(): int
    {
        $status = @file_get_contents('/proc/self/status');
        if ($status !== false && preg_match('/^VmRSS:\s+(\d+)/m', $status, $m) === 1) {
            return (int) $m[1] * 1024;
        }
        return memory_get_usage(true);
    }
}

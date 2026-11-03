<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Session\SqliteStore;
use Bia\Tui\Style;

/**
 * Usage as `/usage` and `bia usage` show it, over the last 14 days: requests
 * answered, tokens with the share read from the cache, the mean time to the
 * first token, and tokens a second, by day, by model and by provider.
 */
final class UsageReport
{
    /** How far back usage looks. */
    public const SPAN = 14 * 86400;

    /** The panel's tabs, each with its title. */
    public const TABS = ['day' => 'Days', 'model' => 'Models', 'provider' => 'Providers'];

    /**
     * Each table one after the other, as `bia usage` prints them.
     *
     * @return list<string>
     */
    public static function lines(SqliteStore $store, ?float $now = null): array
    {
        $lines = [];
        foreach (array_keys(self::TABS) as $tab) {
            $table = self::tab($store, $tab, $now)['lines'];
            if ($table === []) {
                return [Style::dim('· No requests in the last 14 days')];
            }
            if ($tab === 'provider' && count($table) <= 2) {
                continue; // one provider: the models said it all
            }
            if ($tab === 'day') {
                $table = [...self::heatmap($store, $now), '', ...$table];
            }
            array_push($lines, ...($lines === [] ? [Style::dim('· Usage · last 14 days')] : ['']), ...array_map(static fn (string $line): string => '  ' . $line, $table));
        }
        return $lines;
    }

    /** Weeks the heat map covers: about the days usage is kept. */
    public const WEEKS = 13;

    /**
     * Requests a day as GitHub draws contributions: a column a week, a row
     * a weekday, Monday first, each day a green shade by how busy it was
     * against the busiest, dim `·` for none; months above, a key below.
     *
     * @return list<string>
     */
    public static function heatmap(SqliteStore $store, ?float $now = null): array
    {
        $now ??= microtime(true);
        // Dates only, counted as SQLite groups days: no zone or summer time to step over.
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable($store->localDate($now), $utc);
        $start = $today->modify('monday this week')->modify('-' . (self::WEEKS - 1) . ' weeks');
        $counts = array_column($store->usage($now - (self::WEEKS * 7 + 1) * 86400, 'day'), 'requests', 'key');
        $busiest = max([1, ...array_values($counts)]);
        // None, then four shades up to the busiest, in the one green.
        $shades = [Style::dim('·'), ...array_map(Style::ok(...), ['░', '▒', '▓', '█'])];
        $rows = array_fill(0, 7, '');
        $months = '';
        $last = '';
        for ($week = 0; $week < self::WEEKS; $week++) {
            $monday = $start->modify("+{$week} weeks");
            $month = $monday->format('M');
            if ($month !== $last && strlen($months) <= $week * 2) {
                $months = str_pad($months, $week * 2) . $month;
                $last = $month;
            }
            for ($day = 0; $day < 7; $day++) {
                $date = $monday->modify("+{$day} days");
                $count = (int) ($counts[$date->format('Y-m-d')] ?? 0);
                $rows[$day] .= match (true) {
                    $date > $today => ' ',
                    $count === 0 => $shades[0],
                    default => $shades[min(4, (int) ceil($count / $busiest * 4))],
                } . ' ';
            }
        }
        $lines = ['    ' . Style::dim(rtrim($months))];
        foreach (['Mon', '', 'Wed', '', 'Fri', '', ''] as $day => $label) {
            $lines[] = Style::dim(str_pad($label, 4)) . rtrim($rows[$day]);
        }
        $lines[] = '    ' . Style::dim('Less ') . implode(' ', $shades) . Style::dim(' More · requests a day');
        return $lines;
    }

    /**
     * One tab of the panel: its table, the header first and, by day, the
     * total last; empty with no requests.
     *
     * @return array{lines: list<string>, total: bool}
     */
    public static function tab(SqliteStore $store, string $tab, ?float $now = null): array
    {
        if (!isset(self::TABS[$tab])) {
            throw new \InvalidArgumentException('A usage tab is one of: ' . implode(', ', array_keys(self::TABS)) . '.');
        }
        $rows = $store->usage(($now ?? microtime(true)) - self::SPAN, $tab);
        $title = ['day' => 'Day', 'model' => 'Model', 'provider' => 'Provider'][$tab];
        $lines = $rows === [] ? [] : array_map(
            static fn (string $line): string => (string) preg_replace('/^((?:\e\[[0-9;]*m)?)  /', '$1', $line),
            self::table($title, $rows, total: $tab === 'day'),
        );
        return ['lines' => $lines, 'total' => $tab === 'day' && count($rows) > 1];
    }

    /**
     * @param list<array{key: string, requests: int, input: int, cached: int, output: int, first_ms: ?int, tps: ?float}> $rows
     * @return list<string>
     */
    private static function table(string $title, array $rows, bool $total = false): array
    {
        if ($total && count($rows) > 1) {
            $sum = ['key' => 'Total', 'requests' => 0, 'input' => 0, 'cached' => 0, 'output' => 0, 'first_ms' => null, 'tps' => null];
            $timed = $weight = 0;
            $streamed = $seconds = 0.0;
            foreach ($rows as $row) {
                foreach (['requests', 'input', 'cached', 'output'] as $field) {
                    $sum[$field] += $row[$field];
                }
                if ($row['first_ms'] !== null) {
                    $timed += $row['first_ms'] * $row['requests'];
                    $weight += $row['requests'];
                }
                if (($row['tps'] ?? 0) > 0) {
                    $streamed += $row['output'];
                    $seconds += $row['output'] / $row['tps'];
                }
            }
            $sum['first_ms'] = $weight > 0 ? intdiv($timed, $weight) : null;
            $sum['tps'] = $seconds > 0 ? $streamed / $seconds : null;
            $rows[] = $sum;
        }
        $cells = [[$title, 'Requests', 'In', 'Cached', 'Out', 'First token', 'Tok/s']];
        foreach ($rows as $row) {
            $cells[] = [
                $row['key'],
                (string) $row['requests'],
                self::tokens($row['input']),
                $row['input'] > 0 && $row['cached'] > 0 ? self::tokens($row['cached']) . ' (' . self::percent($row['cached'], $row['input']) . ')' : '–',
                self::tokens($row['output']),
                $row['first_ms'] === null ? '–' : self::seconds($row['first_ms']),
                ($row['tps'] ?? null) === null ? '–' : (string) (int) round($row['tps']),
            ];
        }
        $widths = [];
        foreach ($cells as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strwidth($cell));
            }
        }
        $lines = [];
        foreach ($cells as $index => $row) {
            $parts = [];
            foreach ($row as $column => $cell) {
                $pad = str_repeat(' ', $widths[$column] - mb_strwidth($cell));
                // The first column to the left, numbers to the right.
                $parts[] = $column === 0 ? $cell . $pad : $pad . $cell;
            }
            $line = '  ' . implode('  ', $parts);
            $lines[] = $index === 0 || $row[0] === 'Total' ? Style::dim($line) : $line;
        }
        return $lines;
    }

    private static function percent(int $part, int $whole): string
    {
        $share = $whole > 0 ? $part * 100 / $whole : 0;
        return ($share < 10 && $share !== floor($share) ? number_format($share, 1) : (string) round($share)) . '%';
    }

    public static function tokens(int $tokens): string
    {
        return match (true) {
            $tokens >= 1_000_000 => number_format($tokens / 1_000_000, 1) . 'M',
            $tokens >= 10_000 => round($tokens / 1000) . 'k',
            $tokens >= 1000 => number_format($tokens / 1000, 1) . 'k',
            default => (string) $tokens,
        };
    }

    private static function seconds(int $milliseconds): string
    {
        return $milliseconds < 1000 ? $milliseconds . 'ms' : number_format($milliseconds / 1000, 1) . 's';
    }
}

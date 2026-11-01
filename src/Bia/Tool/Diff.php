<?php

declare(strict_types=1);

namespace Bia\Tool;

/**
 * A short line diff of a file tool's change, for the user to see what was
 * done, never for the model: it is kept in the tool result's data.
 *
 * Each line is a mark and its text: "-" removed, "+" added, " " context,
 * "@" a new hunk at the line number given, "=" a file of a patch, and "…"
 * the number of changed lines left out past the limit.
 */
final class Diff
{
    /** Changed lines shown before the rest is counted. */
    public const LIMIT = 40;

    /** Lines of the old and new middle beyond which no matching is tried. */
    private const MATCH_LIMIT = 300;

    /**
     * @return list<string>
     */
    public static function between(string $before, string $after, int $context = 1): array
    {
        $old = self::split($before);
        $new = self::split($after);
        $prefix = 0;
        while ($prefix < count($old) && $prefix < count($new) && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < count($old) - $prefix && $suffix < count($new) - $prefix
            && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]) {
            $suffix++;
        }
        $a = array_slice($old, $prefix, count($old) - $prefix - $suffix);
        $b = array_slice($new, $prefix, count($new) - $prefix - $suffix);
        if ($a === [] && $b === []) {
            return [];
        }
        // Operations over the whole file: [mark, old line number, text].
        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = [' ', $i + 1, $old[$i]];
        }
        foreach (self::middle($a, $b) as [$mark, $text, $offset]) {
            $ops[] = [$mark, $prefix + $offset + 1, $text];
        }
        for ($i = count($old) - $suffix; $i < count($old); $i++) {
            $ops[] = [' ', $i + 1, $old[$i]];
        }
        return self::hunks($ops, $context);
    }

    /**
     * The changed lines of a unified patch, as it is.
     *
     * @return list<string>
     */
    public static function ofPatch(string $patch): array
    {
        $lines = [];
        $changed = 0;
        foreach (preg_split('/\r?\n/', $patch) ?: [] as $line) {
            if (str_starts_with($line, '+++ ')) {
                $path = preg_replace('#^\+\+\+ (b/)?#', '', $line);
                if ($path !== '/dev/null') {
                    $lines[] = '=' . $path;
                }
            } elseif (str_starts_with($line, '--- ') || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
                continue;
            } elseif (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $match) === 1) {
                $lines[] = '@' . $match[1];
            } elseif ($line !== '' && ($line[0] === '+' || $line[0] === '-')) {
                if (++$changed > self::LIMIT) {
                    continue;
                }
                $lines[] = $line;
            } elseif ($line !== '' && $line[0] === ' ' && $changed <= self::LIMIT) {
                $lines[] = $line;
            }
        }
        if ($changed > self::LIMIT) {
            $lines[] = '…' . ($changed - self::LIMIT);
        }
        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", str_replace("\r\n", "\n", $text));
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * The changed middle as removals and additions, matched up by their
     * longest common lines when small enough; else all removed, then all added.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{string, string, int}> Mark, text, and the old line offset it sits at.
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n === 0 || $m === 0 || $n > self::MATCH_LIMIT || $m > self::MATCH_LIMIT) {
            $ops = [];
            foreach ($a as $i => $line) {
                $ops[] = ['-', $line, $i];
            }
            foreach ($b as $line) {
                $ops[] = ['+', $line, $n];
            }
            return $ops;
        }
        // Lengths of the longest common subsequence of each pair of suffixes.
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $length[$i][$j] = $a[$i] === $b[$j] ? $length[$i + 1][$j + 1] + 1 : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $ops[] = [' ', $a[$i], $i];
                $i++;
                $j++;
            } elseif ($i < $n && ($j === $m || $length[$i + 1][$j] >= $length[$i][$j + 1])) {
                // Removals before additions, as diffs are read.
                $ops[] = ['-', $a[$i], $i];
                $i++;
            } else {
                $ops[] = ['+', $b[$j], $i];
                $j++;
            }
        }
        return $ops;
    }

    /**
     * Changes with a little context around each, as hunks, at most LIMIT changed lines.
     *
     * @param list<array{string, int, string}> $ops
     * @return list<string>
     */
    private static function hunks(array $ops, int $context): array
    {
        $count = count($ops);
        $keep = array_fill(0, $count, false);
        foreach ($ops as $index => [$mark]) {
            if ($mark !== ' ') {
                for ($k = max(0, $index - $context); $k <= min($count - 1, $index + $context); $k++) {
                    $keep[$k] = true;
                }
            }
        }
        $lines = [];
        $changed = 0;
        $open = false;
        foreach ($ops as $index => [$mark, $number, $text]) {
            if (!$keep[$index]) {
                $open = false;
                continue;
            }
            if ($mark !== ' ' && ++$changed > self::LIMIT) {
                continue;
            }
            if ($changed > self::LIMIT) {
                continue;
            }
            if (!$open) {
                $lines[] = '@' . $number;
                $open = true;
            }
            $lines[] = $mark . $text;
        }
        if ($changed > self::LIMIT) {
            $lines[] = '…' . ($changed - self::LIMIT);
        }
        return $lines;
    }
}

<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Fuzzy matching for pickers: the query's characters must appear in order;
 * matches at the start, after a separator and close together rank higher.
 */
final class Fuzzy
{
    /**
     * @return int|null Higher is better; null when it does not match.
     */
    public static function score(string $query, string $candidate): ?int
    {
        $query = strtolower(str_replace(' ', '', $query));
        return $query === '' ? 0 : self::scoreLower($query, strtolower($candidate));
    }

    /**
     * The matching items, best first; equal ones in their order.
     *
     * @template T
     * @param list<T> $items
     * @param \Closure(T): string $text
     * @param list<string>|null $lower Each item's text lowercased already, for a long list asked often.
     * @return list<T>
     */
    public static function sort(string $query, array $items, \Closure $text, ?int $limit = null, ?array $lower = null): array
    {
        $query = strtolower(str_replace(' ', '', $query));
        if ($query === '') {
            return $limit === null ? $items : array_slice($items, 0, $limit);
        }
        // Score and position in one integer, so that C sorts them: higher score first, then earlier.
        $count = count($items);
        $keys = [];
        $first = $query[0];
        foreach ($items as $index => $item) {
            $candidate = $lower[$index] ?? strtolower($text($item));
            if (!str_contains($candidate, $first)) {
                continue;
            }
            $score = self::scoreLower($query, $candidate);
            if ($score !== null) {
                $keys[] = $score * $count + ($count - 1 - $index);
            }
        }
        rsort($keys, SORT_NUMERIC);
        if ($limit !== null) {
            $keys = array_slice($keys, 0, $limit);
        }
        $sorted = [];
        foreach ($keys as $key) {
            $sorted[] = $items[$count - 1 - (($key % $count + $count) % $count)];
        }
        return $sorted;
    }

    private static function scoreLower(string $query, string $text): ?int
    {
        $score = 0;
        $position = 0;
        $previous = -2;
        $queryLength = strlen($query);
        for ($i = 0; $i < $queryLength; $i++) {
            $found = strpos($text, $query[$i], $position);
            if ($found === false) {
                return null;
            }
            $score += 1;
            if ($found === $previous + 1) {
                $score += 5; // consecutive
            }
            if ($found === 0 || str_contains('/-_. ', $text[$found - 1])) {
                $score += 8; // at a word start
            }
            $previous = $found;
            $position = $found + 1;
        }
        $base = strrpos($text, '/');
        if ($base !== false && str_contains(substr($text, $base + 1), $query)) {
            $score += 20; // in the file name itself
        }
        if (str_contains($text, $query)) {
            $score += 10;
        }
        return $score * 100 - min(99, strlen($text));
    }
}

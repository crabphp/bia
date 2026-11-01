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
        if ($query === '') {
            return 0;
        }
        $text = strtolower($candidate);
        $length = strlen($text);
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
        return $score * 100 - min(99, $length);
    }

    /**
     * The matching items, best first.
     *
     * @template T
     * @param list<T> $items
     * @param \Closure(T): string $text
     * @return list<T>
     */
    public static function sort(string $query, array $items, \Closure $text, ?int $limit = null): array
    {
        $scored = [];
        foreach ($items as $index => $item) {
            $score = self::score($query, $text($item));
            if ($score !== null) {
                $scored[] = [$score, $index, $item];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $sorted = array_column($scored, 2);
        return $limit === null ? $sorted : array_slice($sorted, 0, $limit);
    }
}

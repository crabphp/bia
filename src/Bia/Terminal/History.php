<?php

declare(strict_types=1);

namespace Bia\Terminal;

/**
 * The prompts typed in conversations, kept across runs: one JSON string per
 * line, so a prompt of several lines stays one entry.
 */
final readonly class History
{
    public function __construct(
        private string $path,
        private int $limit = 500,
    ) {}

    /**
     * @return list<string> Oldest first.
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > $this->limit * 2) {
            $lines = array_slice($lines, -$this->limit);
            @file_put_contents($this->path, implode("\n", $lines) . "\n", LOCK_EX);
        }
        $entries = [];
        foreach (array_slice($lines, -$this->limit) as $line) {
            $entry = json_decode($line, true);
            if (is_string($entry) && $entry !== '') {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    public function add(string $text): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $previous = umask(0077);
        try {
            @file_put_contents($this->path, json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
        } finally {
            umask($previous);
        }
    }
}

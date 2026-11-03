<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * A surface that draws nothing and keeps what is printed on it, as a
 * subagent's run is kept inline, to be printed whole when asked for.
 */
final class Transcript implements Surface
{
    /** @var list<string> */
    private array $lines = [];
    private string $partial = '';
    /** The lines being drawn, not kept yet, as on a screen: printing after them keeps them. */
    private string $draft = '';

    public function __construct(private readonly Terminal $terminal) {}

    /** What was printed, finished lines and the one under way. */
    public function text(): string
    {
        $rest = $this->partial . $this->draft;
        return implode("\n", [...$this->lines, ...($rest !== '' ? [$rest] : [])]);
    }

    public function print(string $text): void
    {
        $parts = explode("\n", $this->partial . $this->draft . $text);
        $this->draft = '';
        $this->partial = (string) array_pop($parts);
        array_push($this->lines, ...$parts);
    }

    public function fold(string $closed, string $open): void
    {
        if ($this->partial . $this->draft !== '') {
            $this->print("\n");
        }
        $this->print($closed . "\n");
    }

    public function gap(): void
    {
        if ($this->partial . $this->draft !== '') {
            $this->print("\n");
        }
        if ($this->lines !== [] && end($this->lines) !== '') {
            $this->lines[] = '';
        }
    }

    public function atLineStart(): bool
    {
        return $this->partial . $this->draft === '';
    }

    public function here(): int
    {
        return count($this->lines);
    }

    public function width(): int
    {
        return max(1, $this->terminal->columns() - 2 * Screen::MARGIN);
    }

    public function draft(string $text): void
    {
        $this->draft = $text;
    }
    public function folds(bool $open): void {}
    public function change(int $line, string $text): void {}
    public function collapse(int $from, int $to, string $summary): void {}
    public function burst(string $chars): void {}
    public function set(array $lines, ?array $cursor = null): void {}
    public function flush(): void {}
    public function close(): void {}
    public function redraw(): void {}
    public function clear(bool $scrollback = false): void {}
    public function scroll(int $rows): void {}
    public function follow(): void {}
    public function page(): int
    {
        return 0;
    }
    public function overlay(array $lines): void {}
    public function metrics(): array
    {
        return ['fps' => 0, 'frame' => 0.0];
    }
    public function pointer(string $action, int $column, int $row): ?string
    {
        return null;
    }
}

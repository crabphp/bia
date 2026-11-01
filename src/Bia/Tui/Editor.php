<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * A multi-line text input with history.
 *
 * Enter submits; Ctrl-J, Alt-Enter (or Shift-Enter where the terminal tells
 * it apart) and a line ending in a backslash start a new line. Pastes keep
 * their newlines. Up and Down move between lines, and past the first or last
 * line through the history.
 */
final class Editor
{
    /** @var list<string> */
    private array $lines = [''];
    private int $row = 0;
    private int $column = 0;
    /** @var list<string> */
    private array $history;
    private int $browsing;
    private string $draft = '';

    /**
     * @param list<string> $history Oldest first.
     */
    public function __construct(array $history = [], private readonly string $prompt = '› ')
    {
        $this->history = $history;
        $this->browsing = count($history);
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    public function empty(): bool
    {
        return $this->lines === [''];
    }

    public function set(string $text): void
    {
        $this->lines = explode("\n", $text);
        $this->row = count($this->lines) - 1;
        $this->column = mb_strlen($this->lines[$this->row], 'UTF-8');
    }

    public function clear(): void
    {
        $this->set('');
        $this->browsing = count($this->history);
    }

    public function remember(string $text): void
    {
        if ($text !== '' && end($this->history) !== $text) {
            $this->history[] = $text;
        }
        $this->browsing = count($this->history);
    }

    /**
     * The text before the cursor on its line.
     */
    public function beforeCursor(): string
    {
        return mb_substr($this->lines[$this->row], 0, $this->column, 'UTF-8');
    }

    /**
     * Replaces the given number of characters before the cursor, as a
     * completion does.
     */
    public function replaceBefore(int $characters, string $text): void
    {
        $line = $this->lines[$this->row];
        $start = max(0, $this->column - $characters);
        $this->lines[$this->row] = mb_substr($line, 0, $start, 'UTF-8') . $text . mb_substr($line, $this->column, null, 'UTF-8');
        $this->column = $start + mb_strlen($text, 'UTF-8');
    }

    /**
     * Applies a key.
     *
     * @return bool|null True when Enter submits the text; false when the key
     *                   was used; null when it is not the editor's.
     */
    public function key(Key $key): ?bool
    {
        $line = $this->lines[$this->row];
        $length = mb_strlen($line, 'UTF-8');
        switch ($key->name) {
            case 'char':
                $this->insert($key->text);
                return false;
            case 'paste':
                $this->insert(str_replace("\t", '    ', $key->text));
                return false;
            case 'enter':
                if ($this->column > 0 && $this->column === $length && str_ends_with($line, '\\')) {
                    $this->lines[$this->row] = substr($line, 0, -1);
                    $this->column--;
                    $this->insert("\n");
                    return false;
                }
                return true;
            case 'ctrl-j':
            case 'alt-enter':
                $this->insert("\n");
                return false;
            case 'backspace':
                if ($this->column > 0) {
                    $this->lines[$this->row] = mb_substr($line, 0, $this->column - 1, 'UTF-8') . mb_substr($line, $this->column, null, 'UTF-8');
                    $this->column--;
                } elseif ($this->row > 0) {
                    $this->column = mb_strlen($this->lines[$this->row - 1], 'UTF-8');
                    $this->lines[$this->row - 1] .= $line;
                    array_splice($this->lines, $this->row, 1);
                    $this->row--;
                }
                return false;
            case 'delete':
                if ($this->column < $length) {
                    $this->lines[$this->row] = mb_substr($line, 0, $this->column, 'UTF-8') . mb_substr($line, $this->column + 1, null, 'UTF-8');
                } elseif ($this->row < count($this->lines) - 1) {
                    $this->lines[$this->row] .= $this->lines[$this->row + 1];
                    array_splice($this->lines, $this->row + 1, 1);
                }
                return false;
            case 'left':
                if ($this->column > 0) {
                    $this->column--;
                } elseif ($this->row > 0) {
                    $this->row--;
                    $this->column = mb_strlen($this->lines[$this->row], 'UTF-8');
                }
                return false;
            case 'right':
                if ($this->column < $length) {
                    $this->column++;
                } elseif ($this->row < count($this->lines) - 1) {
                    $this->row++;
                    $this->column = 0;
                }
                return false;
            case 'home':
            case 'ctrl-a':
                $this->column = 0;
                return false;
            case 'end':
            case 'ctrl-e':
                $this->column = $length;
                return false;
            case 'ctrl-left':
                $this->column = self::wordStart($line, $this->column);
                return false;
            case 'ctrl-right':
                $this->column = self::wordEnd($line, $this->column);
                return false;
            case 'ctrl-w':
                $start = self::wordStart($line, $this->column);
                $this->lines[$this->row] = mb_substr($line, 0, $start, 'UTF-8') . mb_substr($line, $this->column, null, 'UTF-8');
                $this->column = $start;
                return false;
            case 'ctrl-u':
                $this->lines[$this->row] = mb_substr($line, $this->column, null, 'UTF-8');
                $this->column = 0;
                return false;
            case 'ctrl-k':
                $this->lines[$this->row] = mb_substr($line, 0, $this->column, 'UTF-8');
                return false;
            case 'up':
                if ($this->row > 0) {
                    $this->row--;
                    $this->column = min($this->column, mb_strlen($this->lines[$this->row], 'UTF-8'));
                } else {
                    $this->browse(-1);
                }
                return false;
            case 'down':
                if ($this->row < count($this->lines) - 1) {
                    $this->row++;
                    $this->column = min($this->column, mb_strlen($this->lines[$this->row], 'UTF-8'));
                } else {
                    $this->browse(1);
                }
                return false;
        }
        return null;
    }

    /**
     * The editor drawn in the given width, and where its cursor is.
     *
     * @return array{0: list<string>, 1: array{0: int, 1: int}}
     */
    public function render(int $width, string $placeholder = ''): array
    {
        $indent = Style::width($this->prompt);
        $room = max(1, $width - $indent);
        $rows = [];
        $cursor = [0, $indent];
        foreach ($this->lines as $index => $line) {
            $wrapped = Style::wrap($line, $room);
            if ($index === $this->row) {
                $before = Style::wrap(mb_substr($line, 0, $this->column, 'UTF-8'), $room);
                $last = end($before);
                $column = mb_strwidth($last, 'UTF-8');
                $offset = count($before) - 1;
                if ($column >= $room) {
                    // At the very end of a full row, the cursor waits at the start of the next.
                    $offset++;
                    $column = 0;
                    if ($offset >= count($wrapped)) {
                        $wrapped[] = '';
                    }
                }
                $cursor = [count($rows) + $offset, $indent + $column];
            }
            foreach ($wrapped as $part => $text) {
                $lead = $index === 0 && $part === 0 ? Style::accent($this->prompt) : str_repeat(' ', $indent);
                $rows[] = $lead . $text;
            }
        }
        if ($this->empty() && $placeholder !== '') {
            $rows = [Style::accent($this->prompt) . Style::dim(Style::truncate($placeholder, $room))];
        }
        return [$rows, $cursor];
    }

    private function insert(string $text): void
    {
        $line = $this->lines[$this->row];
        $before = mb_substr($line, 0, $this->column, 'UTF-8');
        $after = mb_substr($line, $this->column, null, 'UTF-8');
        $parts = explode("\n", $text);
        if (count($parts) === 1) {
            $this->lines[$this->row] = $before . $text . $after;
            $this->column += mb_strlen($text, 'UTF-8');
            return;
        }
        $last = array_pop($parts);
        $first = array_shift($parts);
        array_splice($this->lines, $this->row, 1, [$before . $first, ...$parts, $last . $after]);
        $this->row += count($parts) + 1;
        $this->column = mb_strlen($last, 'UTF-8');
    }

    private function browse(int $step): void
    {
        $next = $this->browsing + $step;
        if ($next < 0 || $next > count($this->history)) {
            return;
        }
        if ($this->browsing === count($this->history)) {
            $this->draft = $this->text();
        }
        $this->browsing = $next;
        $this->set($next === count($this->history) ? $this->draft : $this->history[$next]);
    }

    private static function wordStart(string $line, int $column): int
    {
        $before = mb_substr($line, 0, $column, 'UTF-8');
        return preg_match('/(?:\w+|[^\w\s]+)\s*$/u', $before, $match) === 1
            ? $column - mb_strlen($match[0], 'UTF-8')
            : 0;
    }

    private static function wordEnd(string $line, int $column): int
    {
        $after = mb_substr($line, $column, null, 'UTF-8');
        return preg_match('/^\s*(?:\w+|[^\w\s]+)/u', $after, $match) === 1
            ? $column + mb_strlen($match[0], 'UTF-8')
            : mb_strlen($line, 'UTF-8');
    }
}

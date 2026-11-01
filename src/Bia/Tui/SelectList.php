<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * A list to choose one item from, filtered as you type.
 *
 * It holds no terminal: keys go in, lines come out, so it is drawn wherever
 * it is needed, as a completion menu or a picker.
 */
final class SelectList
{
    /** @var list<array{value: string, label: string, hint: string}> */
    private array $shown;
    private int $cursor = 0;
    private int $top = 0;
    private string $query = '';

    /**
     * @param list<array{value: string, label?: string, hint?: string}> $items
     * @param bool $filters Whether typing filters the list; a completion menu is filtered by its owner instead.
     */
    public function __construct(
        private array $items,
        private readonly int $height = 8,
        ?string $selected = null,
        private readonly bool $filters = true,
        private readonly string $empty = 'No matches',
    ) {
        $this->items = array_map(static fn (array $item): array => [
            'value' => $item['value'],
            'label' => $item['label'] ?? $item['value'],
            'hint' => $item['hint'] ?? '',
        ], $items);
        $this->shown = $this->items;
        if ($selected !== null) {
            foreach ($this->shown as $index => $item) {
                if ($item['value'] === $selected) {
                    $this->move($index);
                    break;
                }
            }
        }
    }

    public function query(): string
    {
        return $this->query;
    }

    public function count(): int
    {
        return count($this->shown);
    }

    /**
     * @return array{value: string, label: string, hint: string}|null
     */
    public function selected(): ?array
    {
        return $this->shown[$this->cursor] ?? null;
    }

    /**
     * Applies a key.
     *
     * @return string|null "choose", "cancel", or null when the list stays open.
     */
    public function key(Key $key): ?string
    {
        switch ($key->name) {
            case 'up':
            case 'shift-tab':
                $this->move($this->cursor === 0 ? count($this->shown) - 1 : $this->cursor - 1);
                return null;
            case 'down':
                $this->move($this->cursor >= count($this->shown) - 1 ? 0 : $this->cursor + 1);
                return null;
            case 'pageup':
                $this->move(max(0, $this->cursor - $this->height));
                return null;
            case 'pagedown':
                $this->move(min(count($this->shown) - 1, $this->cursor + $this->height));
                return null;
            case 'enter':
            case 'tab':
                return $this->selected() === null ? null : 'choose';
            case 'escape':
            case 'ctrl-c':
                return 'cancel';
            case 'char':
                if ($this->filters) {
                    $this->filter($this->query . $key->text);
                }
                return null;
            case 'backspace':
                if ($this->filters && $this->query !== '') {
                    $this->filter(mb_substr($this->query, 0, -1, 'UTF-8'));
                }
                return null;
        }
        return null;
    }

    /**
     * Keeps the items whose label matches the query fuzzily, best first.
     */
    public function filter(string $query): void
    {
        $this->query = $query;
        $this->shown = trim($query) === '' ? $this->items : Fuzzy::sort($query, $this->items, static fn (array $item): string => $item['label']);
        $this->cursor = 0;
        $this->top = 0;
    }

    /**
     * @return list<string>
     */
    public function render(int $width, string $title = ''): array
    {
        $rows = [];
        if ($title !== '') {
            $rows[] = Style::dim(Style::truncate($title . ($this->filters && $this->query !== '' ? "  {$this->query}" : ''), $width));
        }
        if ($this->shown === []) {
            $rows[] = Style::dim('  ' . $this->empty);
            return $rows;
        }
        $labelWidth = 0;
        $hintWidth = 0;
        foreach (array_slice($this->shown, $this->top, $this->height) as $item) {
            $labelWidth = max($labelWidth, mb_strwidth($item['label'], 'UTF-8'));
            $hintWidth = max($hintWidth, mb_strwidth($item['hint'], 'UTF-8'));
        }
        // Labels give way to hints up to half the width, so both stay readable.
        $hintRoom = $hintWidth === 0 ? 0 : min($hintWidth, intdiv($width, 2)) + 2;
        $labelWidth = min($labelWidth, max(10, $width - 4 - $hintRoom));
        foreach (array_slice($this->shown, $this->top, $this->height, true) as $index => $item) {
            $label = Style::truncate($item['label'], $labelWidth);
            $hintRoom = $width - 4 - $labelWidth;
            $hint = $item['hint'] !== '' && $hintRoom > 4 ? Style::truncate($item['hint'], $hintRoom) : '';
            $padded = $label . str_repeat(' ', max(0, $labelWidth - mb_strwidth($label, 'UTF-8')));
            $rows[] = $index === $this->cursor
                ? Style::accent('› ' . $padded) . ($hint !== '' ? '  ' . Style::dim($hint) : '')
                : '  ' . $padded . ($hint !== '' ? '  ' . Style::dim($hint) : '');
        }
        $more = count($this->shown) - $this->height;
        if ($more > 0) {
            $rows[] = Style::dim('  ' . ($this->cursor + 1) . '/' . count($this->shown));
        }
        return $rows;
    }

    private function move(int $index): void
    {
        if ($this->shown === []) {
            return;
        }
        $this->cursor = max(0, min(count($this->shown) - 1, $index));
        if ($this->cursor < $this->top) {
            $this->top = $this->cursor;
        } elseif ($this->cursor >= $this->top + $this->height) {
            $this->top = $this->cursor - $this->height + 1;
        }
    }
}

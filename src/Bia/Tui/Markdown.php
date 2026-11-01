<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Renders a model's markdown for the terminal a line at a time, as it
 * streams: headings and bold in bold, lists with a bullet, quotes and code
 * fences dim, code in the accent. Everything else stays as written, and
 * nothing is hidden but the markup itself, so copied text still reads.
 *
 * It keeps whether a code block is open; one renderer per answer.
 */
final class Markdown
{
    private ?string $fence = null;
    /** The open code block's highlighter, when its language is known. */
    private ?Highlight $code = null;

    /**
     * Renders one finished line, and moves past it.
     */
    public function line(string $line): string
    {
        if (preg_match('/^\s*(`{3,}|~{3,})\s*([\w+#.-]*)/', $line, $match) === 1
            && ($this->fence === null || str_starts_with(ltrim($line), $this->fence))) {
            $opening = $this->fence === null;
            $this->fence = $opening ? $match[1][0] . $match[1][0] . $match[1][0] : null;
            $this->code = $opening && $match[2] !== '' ? Highlight::for($match[2]) : null;
            return Style::dim($line);
        }
        if ($this->fence !== null && $this->code !== null) {
            return $this->code->line($line);
        }
        return $this->render($line);
    }

    /**
     * Renders the line still streaming, without moving past it: a closing
     * marker it may yet get is not waited for.
     */
    public function preview(string $line): string
    {
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
            return Style::dim($line);
        }
        if ($this->fence !== null && $this->code !== null) {
            return $this->code->preview($line);
        }
        return $this->render($line);
    }

    public function reset(): void
    {
        $this->fence = null;
        $this->code = null;
    }

    private function render(string $line): string
    {
        if ($this->fence !== null) {
            return Style::accent($line);
        }
        if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $match) === 1) {
            return Style::bold(self::inline($match[2], bold: false));
        }
        if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line) === 1) {
            return Style::dim('───');
        }
        if (preg_match('/^(\s*)>\s?(.*)$/', $line, $match) === 1) {
            return $match[1] . Style::dim('│ ' . $match[2]);
        }
        if (preg_match('/^(\s*)[-*+]\s+(.*)$/', $line, $match) === 1) {
            return $match[1] . '• ' . self::inline($match[2]);
        }
        return self::inline($line);
    }

    /**
     * Inline code in the accent and **bold** in bold; code is left as it is inside.
     */
    private static function inline(string $text, bool $bold = true): string
    {
        $parts = preg_split('/(`+)(.+?)\1/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $text;
        }
        $out = '';
        for ($i = 0, $n = count($parts); $i < $n; $i += 3) {
            $plain = $parts[$i];
            if ($bold) {
                $plain = (string) preg_replace_callback(
                    // Only **: __ is too often a name's own, as in __init__.
                    '/\*\*(?=\S)(.+?)(?<=\S)\*\*/',
                    static fn (array $m): string => Style::bold($m[1]),
                    $plain,
                );
            }
            $out .= $plain;
            if (isset($parts[$i + 2])) {
                $out .= Style::accent($parts[$i + 2]);
            }
        }
        return $out;
    }
}

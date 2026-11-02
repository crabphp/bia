<?php

declare(strict_types=1);

namespace Bia\Config;

/**
 * Sets one value in the user's config.php, as /model and /render keep what
 * was chosen, and leaves everything else in the file as it was written:
 * comments, env() calls, layout. The file is read with PHP's tokenizer to
 * find the returned array and the key's place in it; the value there is
 * replaced, or the key added where it is missing. What would not parse is
 * never written.
 */
final class ConfigFile
{
    public function __construct(private readonly string $path) {}

    /**
     * @param list<string> $keys The setting's path, as ['tui', 'render'].
     */
    public function set(array $keys, string|int|float|bool $value): void
    {
        $code = is_file($this->path) ? (string) file_get_contents($this->path) : '';
        $code = trim($code) === '' ? "<?php\n\nreturn [\n];\n" : $code;
        $changed = self::with($code, $keys, var_export($value, true));
        try {
            token_get_all($changed, TOKEN_PARSE);
        } catch (\ParseError) {
            throw new \RuntimeException("Could not keep the setting: {$this->path} would not parse after it.");
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create {$directory}.");
        }
        $temporary = $this->path . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($temporary, $changed) === false || !rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException("Could not write {$this->path}.");
        }
        @chmod($this->path, 0600); // it may hold keys
    }

    /**
     * The code with the value at the keys: replaced where the key is, added
     * to the deepest array of the path there is.
     *
     * @param list<string> $keys
     */
    public static function with(string $code, array $keys, string $value): string
    {
        $tokens = token_get_all($code);
        $offsets = [];
        $at = 0;
        foreach ($tokens as $index => $token) {
            $offsets[$index] = $at;
            $at += strlen(is_array($token) ? $token[1] : $token);
        }
        $offsets[count($tokens)] = $at;

        // The array the file returns: the first [ after `return`.
        $open = null;
        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_RETURN) {
                $open = self::next($tokens, $index, '[');
                break;
            }
        }
        if ($open === null) {
            throw new \RuntimeException('The config file does not return an array.');
        }
        foreach ($keys as $depth => $key) {
            [$valueStart, $valueEnd] = self::find($tokens, $open, $key) ?? [null, null];
            $last = $depth === count($keys) - 1;
            if ($valueStart === null) {
                // Missing: added first in this array, with what is left of the path.
                $entry = var_export($key, true) . ' => ' . self::nested(array_slice($keys, $depth + 1), $value) . ',';
                $indent = str_repeat('    ', $depth + 1);
                $insert = $offsets[$open + 1];
                return substr($code, 0, $insert) . "\n" . $indent . $entry . substr($code, $insert);
            }
            if ($last) {
                return substr($code, 0, $offsets[$valueStart]) . $value . substr($code, $offsets[$valueEnd]);
            }
            if (!is_string($tokens[$valueStart]) || $tokens[$valueStart] !== '[') {
                // Set to something not an array here: the whole of it is replaced.
                return substr($code, 0, $offsets[$valueStart]) . self::nested(array_slice($keys, $depth + 1), $value) . substr($code, $offsets[$valueEnd]);
            }
            $open = $valueStart;
        }
        return $code;
    }

    /**
     * Where the key's value is in the array opened at $open, directly in it:
     * the first token of the value and the token after its last; null when
     * the key is not there.
     *
     * @param list<mixed> $tokens
     * @return array{0: int, 1: int}|null
     */
    private static function find(array $tokens, int $open, string $key): ?array
    {
        $depth = 0;
        $count = count($tokens);
        for ($index = $open + 1; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_string($token)) {
                if ($token === '[' || $token === '(') {
                    $depth++;
                } elseif ($token === ']' || $token === ')') {
                    if ($depth === 0) {
                        return null; // the array ended
                    }
                    $depth--;
                }
                continue;
            }
            if ($depth !== 0 || $token[0] !== T_CONSTANT_ENCAPSED_STRING || self::unquote($token[1]) !== $key) {
                continue;
            }
            $arrow = self::skip($tokens, $index + 1);
            if (!is_array($tokens[$arrow] ?? null) || $tokens[$arrow][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            $start = self::skip($tokens, $arrow + 1);
            // The value runs to the comma or bracket that ends it, at its own depth.
            $inner = 0;
            for ($end = $start; $end < $count; $end++) {
                $part = $tokens[$end];
                if (is_string($part)) {
                    if ($part === '[' || $part === '(') {
                        $inner++;
                    } elseif ($part === ']' || $part === ')') {
                        if ($inner === 0) {
                            break;
                        }
                        $inner--;
                    } elseif ($part === ',' && $inner === 0) {
                        break;
                    }
                }
            }
            // Spaces and comments before the end are not the value's.
            while ($end > $start && is_array($tokens[$end - 1]) && in_array($tokens[$end - 1][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $end--;
            }
            return [$start, $end];
        }
        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private static function next(array $tokens, int $index, string $char): ?int
    {
        for ($count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === $char) {
                return $index;
            }
        }
        return null;
    }

    /**
     * The first token from $index that is not space or a comment.
     *
     * @param list<mixed> $tokens
     */
    private static function skip(array $tokens, int $index): int
    {
        while (is_array($tokens[$index] ?? null) && in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $index++;
        }
        return $index;
    }

    private static function unquote(string $literal): string
    {
        return stripcslashes(substr($literal, 1, -1));
    }

    /**
     * @param list<string> $keys
     */
    private static function nested(array $keys, string $value): string
    {
        foreach (array_reverse($keys) as $key) {
            $value = '[' . var_export($key, true) . ' => ' . $value . ']';
        }
        return $value;
    }
}

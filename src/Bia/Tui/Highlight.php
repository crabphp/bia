<?php

declare(strict_types=1);

namespace Bia\Tui;

/**
 * Basic syntax highlighting, a line at a time: keywords in the accent,
 * strings in yellow, comments dim. Each language is one regular expression,
 * built once, so a line costs a single pass; a block comment left open is
 * carried to the next line.
 *
 * Colours are ended by resetting only the colour, never everything, so a
 * background behind the line, as a diff's, stays.
 */
final class Highlight
{
    private const KEYWORD = "\e[36m";
    private const STRING = "\e[33m";
    private const COMMENT = "\e[2m";

    /** Language names and file extensions, to the language they are. */
    private const NAMES = [
        'php' => 'php', 'phtml' => 'php',
        'js' => 'js', 'jsx' => 'js', 'mjs' => 'js', 'cjs' => 'js', 'javascript' => 'js',
        'ts' => 'js', 'tsx' => 'js', 'mts' => 'js', 'typescript' => 'js', 'vue' => 'js', 'svelte' => 'js',
        'py' => 'python', 'python' => 'python', 'pyi' => 'python',
        'go' => 'go', 'golang' => 'go',
        'rs' => 'rust', 'rust' => 'rust',
        'c' => 'c', 'h' => 'c', 'cc' => 'c', 'cpp' => 'c', 'cxx' => 'c', 'hpp' => 'c', 'c++' => 'c',
        'java' => 'c', 'kt' => 'c', 'kts' => 'c', 'kotlin' => 'c', 'cs' => 'c', 'csharp' => 'c',
        'swift' => 'c', 'scala' => 'c', 'dart' => 'c',
        'sh' => 'sh', 'bash' => 'sh', 'zsh' => 'sh', 'shell' => 'sh', 'console' => 'sh', 'fish' => 'sh',
        'json' => 'json', 'jsonc' => 'json',
        'yaml' => 'yaml', 'yml' => 'yaml', 'toml' => 'yaml', 'ini' => 'yaml', 'env' => 'yaml',
        'sql' => 'sql',
    ];

    private const KEYWORDS = [
        'php' => 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enum extends false final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new null or print private protected public readonly require require_once return self static switch throw trait true try unset use var while xor yield parent mixed void never int float string bool iterable object',
        'js' => 'as async await break case catch class const continue debugger default delete do else enum export extends false finally for from function get if implements import in instanceof interface let new null of package private protected public return set static super switch this throw true try type typeof undefined var void while with yield',
        'python' => 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return self True try while with yield match case',
        'go' => 'break case chan const continue default defer else fallthrough false for func go goto if import interface map nil package range return select struct switch true type var',
        'rust' => 'as async await break const continue crate dyn else enum extern false fn for if impl in let loop match mod move mut pub ref return self Self static struct super trait true type unsafe use where while',
        'c' => 'abstract auto bool break case catch char class const continue default delete do double else enum explicit extern false final float for fun func if import inline int interface internal let long namespace new null nullptr object override package private protected public return short signed sizeof static struct super switch template this throw true try typedef typename union unsigned using val var virtual void volatile when while',
        'sh' => 'case do done elif else esac export fi for function if in local return then until while echo exit set unset source',
        'json' => 'true false null',
        'yaml' => 'true false null yes no on off',
        'sql' => 'select from where and or not insert into values update set delete create table index drop alter add primary key foreign references join left right inner outer on group by order having limit offset as distinct union all null is in exists case when then else end begin commit rollback',
    ];

    /** @var array<string, string> Each language's one expression. */
    private static array $patterns = [];
    /** @var array<string, array<string, true>> Each language's keywords, to look up. */
    private static array $keywords = [];

    private bool $inBlock = false;

    private function __construct(private readonly string $language) {}

    /**
     * A highlighter for a language name or a file path; null for a language not known.
     */
    public static function for(string $nameOrPath): ?self
    {
        $name = strtolower($nameOrPath);
        $dot = strrpos($name, '.');
        $name = $dot === false ? $name : substr($name, $dot + 1);
        $language = self::NAMES[$name] ?? null;
        return $language === null || !Style::color() ? null : new self($language);
    }

    /**
     * Highlights a line, and carries an open block comment to the next.
     */
    public function line(string $code): string
    {
        return $this->render($code, true);
    }

    /**
     * Highlights a line still streaming, without carrying anything on.
     */
    public function preview(string $code): string
    {
        return $this->render($code, false);
    }

    private function render(string $code, bool $carry): string
    {
        $out = '';
        $inBlock = $this->inBlock;
        if ($inBlock) {
            $end = strpos($code, '*/');
            if ($end === false) {
                return self::COMMENT . $code . "\e[22m";
            }
            $out = self::COMMENT . substr($code, 0, $end + 2) . "\e[22m";
            $code = substr($code, $end + 2);
            $inBlock = false;
        }
        // One split into tokens and the text between; each token is known by how it starts.
        $keywords = self::$keywords[$this->language] ??= array_fill_keys(explode(' ', self::KEYWORDS[$this->language]), true);
        $fold = $this->language === 'sql';
        $parts = preg_split(self::pattern($this->language), $code, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts === false ? [$code] : $parts as $index => $part) {
            if ($index % 2 === 0 || $part === '') {
                $out .= $part;
                continue;
            }
            $first = $part[0];
            if ($first === '"' || $first === "'" || $first === '`') {
                $out .= self::STRING . $part . "\e[39m";
            } elseif ($first === '/' && ($part[1] ?? '') === '*') {
                $inBlock = strlen($part) < 4 || !str_ends_with($part, '*/');
                $out .= self::COMMENT . $part . "\e[22m";
            } elseif ($first === '/' || $first === '#' || ($first === '-' && ($part[1] ?? '') === '-')) {
                $out .= self::COMMENT . $part . "\e[22m";
            } elseif (isset($keywords[$fold ? strtolower($part) : $part])) {
                $out .= self::KEYWORD . $part . "\e[39m";
            } else {
                $out .= $part; // a name, or a variable or member that shares a keyword's
            }
        }
        if ($carry) {
            $this->inBlock = $inBlock;
        }
        return $out;
    }

    private static function pattern(string $language): string
    {
        if (isset(self::$patterns[$language])) {
            return self::$patterns[$language];
        }
        $comment = match ($language) {
            'php' => '\/\/.*|#(?!\[).*',
            'python', 'sh', 'yaml' => '#.*',
            'sql' => '--.*',
            'json' => '(?!)',
            default => '\/\/.*',
        };
        $block = in_array($language, ['python', 'sh', 'yaml', 'json'], true) ? '(?!)' : '\/\*.*?(?:\*\/|$)';
        $strings = '"(?:\\\\.|[^"\\\\])*"?|\'(?:\\\\.|[^\'\\\\])*\'?';
        if (in_array($language, ['js', 'go', 'sh'], true)) {
            $strings .= '|`(?:\\\\.|[^`\\\\])*`?';
        }
        // Variables and members first, so `$class` or `->list` are names, not keywords.
        $name = '\$\w+|(?:->|::|\.)\w+';
        return self::$patterns[$language] = "/({$block}|{$comment}|{$strings}|{$name}|\\b[A-Za-z_]\\w*)/";
    }
}

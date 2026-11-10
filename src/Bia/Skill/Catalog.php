<?php

declare(strict_types=1);

namespace Bia\Skill;

/**
 * The skills there are: each a folder with a SKILL.md, in the user's
 * `~/.agents/skills` and the project's `.agents/skills`, the format other
 * agents share. A project's skill takes the place of the user's of its name.
 * Before them come Bia's own, on writing a plugin, and each installed
 * plugin's, in its `skills` folder: the user's and a project's replace those.
 *
 * Read again each time it is asked, which is each step: a skill added
 * during a session is there at the next. It costs a directory listing and
 * the head of each SKILL.md; with no folders, nothing.
 */
final readonly class Catalog
{
    /** Bytes of a SKILL.md read to find its name and description. */
    private const HEAD = 8192;

    /**
     * @param list<string> $directories Where skills are looked for, the later taking the place of the earlier.
     */
    public function __construct(private array $directories, private ?string $plugins = null) {}

    /**
     * @param string|null $plugins Where plugins are installed, `~/.bia/plugins`, for their skills and Bia's own.
     */
    public static function for(string $home, string $workspace, ?string $plugins = null): self
    {
        return new self([rtrim($home, '/') . '/.agents/skills', rtrim($workspace, '/') . '/.agents/skills'], $plugins);
    }

    /**
     * @return array<string, Skill> By name, in name order.
     */
    public function all(): array
    {
        $skills = [];
        $directories = $this->directories;
        if ($this->plugins !== null) {
            $plugins = [];
            foreach (is_dir($this->plugins) ? (scandir($this->plugins) ?: []) : [] as $plugin) {
                if ($plugin[0] !== '.') {
                    $plugins[] = "{$this->plugins}/{$plugin}/skills";
                }
            }
            $directories = [dirname(__DIR__) . '/Plugin/skills', ...$plugins, ...$directories];
        }
        foreach ($directories as $directory) {
            foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $entry) {
                $file = "{$directory}/{$entry}/SKILL.md";
                if ($entry[0] === '.' || !is_file($file)) {
                    continue;
                }
                $skill = self::read($file, $entry);
                if ($skill !== null) {
                    $skills[$skill->name] = $skill;
                }
            }
        }
        ksort($skills);
        return $skills;
    }

    /**
     * A skill's name and description, from its frontmatter: a block of
     * `key: value` lines between `---` lines, a value quoted or folded over
     * the lines below it. Without a description, it is not a skill a model
     * could choose, and is left out.
     */
    private static function read(string $file, string $folder): ?Skill
    {
        $head = (string) @file_get_contents($file, false, null, 0, self::HEAD);
        if (preg_match('/\A\xEF?\xBB?\xBF?---\r?\n(.*?)\r?\n---\s*(?:\r?\n|\z)/s', $head, $m) !== 1) {
            return null;
        }
        $fields = [];
        $lines = preg_split('/\r?\n/', $m[1]) ?: [];
        for ($i = 0, $n = count($lines); $i < $n; $i++) {
            if (preg_match('/^([A-Za-z][\w-]*):\s*(.*)$/', $lines[$i], $field) !== 1) {
                continue;
            }
            $value = trim($field[2]);
            if (preg_match('/^[>|][-+]?$/', $value) === 1) {
                // Folded or literal: the indented lines that follow.
                $parts = [];
                while ($i + 1 < $n && preg_match('/^\s+\S/', $lines[$i + 1]) === 1) {
                    $parts[] = trim($lines[++$i]);
                }
                $value = implode($value[0] === '|' ? "\n" : ' ', $parts);
            } elseif (preg_match('/^(["\'])(.*)\1$/s', $value, $quoted) === 1) {
                $value = $quoted[1] === '"' ? stripcslashes($quoted[2]) : str_replace("''", "'", $quoted[2]);
            }
            $fields[strtolower($field[1])] = $value;
        }
        $description = trim((string) ($fields['description'] ?? ''));
        if ($description === '') {
            return null;
        }
        $name = trim((string) ($fields['name'] ?? '')) ?: $folder;
        if (preg_match('/^[\w.:-]{1,64}$/', $name) !== 1) {
            return null;
        }
        return new Skill($name, preg_replace('/\s+/', ' ', $description) ?? $description, dirname($file));
    }
}

<?php

declare(strict_types=1);

namespace Bia\Memory;

use Bia\Tool\AtomicFile;
use Bia\Tool\Diff;

/**
 * What Bia keeps from one session to the next, as plain files the user can
 * read, edit and delete.
 *
 * About the user, shared by every project, under `<home>/diary`: a page a
 * day (`2026-11-10.md`), one line an entry, only ever added to; and
 * `ABOUT.md`, the standing facts drawn from them, one line each, replaced
 * in place.
 *
 * About one project, under `<home>/projects/<project>/memory`: a file a
 * memory, and `MEMORY.md`, the index of them, a line each. It is outside
 * the repository, so nothing of it is committed.
 *
 * A rule the user lays down for a project is not a memory: it is written
 * into the project's own AGENTS.md, and read as an instruction from then on.
 *
 * Only the facts, the last two pages and the index go in the system prompt,
 * each capped; a memory's text and older pages are read when asked for.
 */
final readonly class Memory
{
    /** Bytes of ABOUT.md, of the diary's last pages and of a project's index put in the prompt. */
    public const ABOUT_BYTES = 4096;
    public const DIARY_BYTES = 4096;
    public const INDEX_BYTES = 8192;

    /** Lines of a project's index put in the prompt. */
    public const INDEX_LINES = 200;

    /** Characters of one diary entry, fact or description. */
    private const LINE = 500;

    /** Bytes of one project memory. */
    private const TEXT_BYTES = 16_384;

    /** Lines a search gives at most. */
    private const FOUND = 40;

    /** Words that make what the user says a rule, not a request. */
    private const FORCED = '/\b(must(?:\s+not|n[\'’]t)?|always|never|from now on|every time|do(?:\s+not|n[\'’]t) ever|as a rule|remember (?:that|to))\b/i';

    private string $root;

    /**
     * @param string $home Where Bia's own files live, `~/.bia`.
     * @param string $workspace Where Bia was started.
     * @param (\Closure(): \DateTimeImmutable)|null $clock The time now, in the machine's zone.
     */
    public function __construct(private string $home, string $workspace, private ?\Closure $clock = null)
    {
        $this->root = self::root($workspace);
    }

    /**
     * The project a workspace belongs to: its repository's root, and for a
     * worktree the checkout it was made from, so all of them share one
     * memory; the workspace itself outside a repository.
     */
    public static function root(string $workspace): string
    {
        for ($directory = $workspace; ; $directory = dirname($directory)) {
            $git = $directory . '/.git';
            if (is_dir($git)) {
                return $directory;
            }
            if (is_file($git)) {
                $main = preg_match('#^gitdir: (.+)/\.git/worktrees/[^/]+\s*$#m', (string) @file_get_contents($git), $match) === 1 ? $match[1] : '';
                return $main !== '' && is_dir($main) ? $main : $directory;
            }
            if ($directory === dirname($directory)) {
                return $workspace;
            }
        }
    }

    public function diaryDirectory(): string
    {
        return $this->home . '/diary';
    }

    public function aboutPath(): string
    {
        return $this->diaryDirectory() . '/ABOUT.md';
    }

    public function projectDirectory(): string
    {
        return $this->home . '/projects/' . (trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $this->root), '-') ?: 'root') . '/memory';
    }

    /**
     * The file a project's rules are written in: its AGENTS.md, or its
     * CLAUDE.md where that is the one it has.
     */
    public function rulesPath(): string
    {
        return !is_file($this->root . '/AGENTS.md') && is_file($this->root . '/CLAUDE.md')
            ? $this->root . '/CLAUDE.md'
            : $this->root . '/AGENTS.md';
    }

    /**
     * Adds an entry to today's page: the time, the project, and what was
     * said. The line as written.
     */
    public function note(string $text): string
    {
        $text = self::line($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A diary entry needs text.');
        }
        $now = $this->now();
        $line = '- ' . $now->format('H:i') . ' ' . basename($this->root) . ' · ' . $text;
        $path = $this->diaryDirectory() . '/' . $now->format('Y-m-d') . '.md';
        self::directory(dirname($path));
        // Added to in one write: two Bias at once never lose each other's entry.
        if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$path}.");
        }
        return $line;
    }

    /**
     * The standing facts about the user, by name.
     *
     * @return array<string, string>
     */
    public function about(): array
    {
        $facts = [];
        foreach (self::lines($this->aboutPath()) as $line) {
            if (preg_match('/^- ([a-z0-9-]+): (.*)$/', $line, $match) === 1) {
                $facts[$match[1]] = $match[2];
            }
        }
        return $facts;
    }

    /**
     * Writes a fact about the user, in the place of the one of that name.
     */
    public function saveAbout(string $name, string $text): void
    {
        $name = self::name($name);
        $text = self::line($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A fact needs text.');
        }
        $this->replace($this->aboutPath(), "- {$name}: ", "- {$name}: {$text}");
    }

    public function forgetAbout(string $name): bool
    {
        return $this->replace($this->aboutPath(), '- ' . self::name($name) . ': ', null);
    }

    /**
     * A project's memories: each name and the line that says what it holds.
     *
     * @return array<string, string>
     */
    public function memories(): array
    {
        $memories = [];
        foreach (self::lines($this->projectDirectory() . '/MEMORY.md') as $line) {
            if (preg_match('/^- \[([a-z0-9-]+)\]\([^)]*\) — (.*)$/u', $line, $match) === 1) {
                $memories[$match[1]] = $match[2];
            }
        }
        return $memories;
    }

    /**
     * Writes a project memory, in the place of the one of that name, and its
     * line in the index. Whether the index is now longer than what is read
     * of it, and so needs shortening.
     */
    public function save(string $name, string $description, string $text): bool
    {
        $name = self::name($name);
        $description = self::line($description);
        $text = trim($text);
        if ($description === '' || $text === '') {
            throw new \InvalidArgumentException('A project memory needs a description and text.');
        }
        if (strlen($text) > self::TEXT_BYTES) {
            throw new \InvalidArgumentException('A memory holds one fact, in at most ' . intdiv(self::TEXT_BYTES, 1024) . ' KB: shorten it, or split it.');
        }
        $directory = $this->projectDirectory();
        AtomicFile::write("{$directory}/{$name}.md", "---\nname: {$name}\ndescription: {$description}\nmodified: " . $this->now()->format('Y-m-d') . "\n---\n\n{$text}\n");
        $index = "{$directory}/MEMORY.md";
        $this->replace($index, "- [{$name}](", "- [{$name}]({$name}.md) — {$description}");
        return filesize($index) > self::INDEX_BYTES || count(self::lines($index)) > self::INDEX_LINES;
    }

    public function forget(string $name): bool
    {
        $name = self::name($name);
        $directory = $this->projectDirectory();
        $listed = $this->replace("{$directory}/MEMORY.md", "- [{$name}](", null);
        $file = "{$directory}/{$name}.md";
        return (is_file($file) && unlink($file)) || $listed;
    }

    /**
     * A project memory's text, with when it was last written; null for none.
     */
    public function read(string $name): ?string
    {
        $file = $this->projectDirectory() . '/' . self::name($name) . '.md';
        if (!is_file($file)) {
            return null;
        }
        $content = (string) file_get_contents($file, false, null, 0, self::TEXT_BYTES + 1024);
        $modified = preg_match('/^modified: (\S+)$/m', $content, $match) === 1 ? $match[1] : date('Y-m-d', (int) filemtime($file));
        $text = trim((string) preg_replace('/\A---\n.*?\n---\n/s', '', $content));
        return "[written {$modified}]\n{$text}";
    }

    /**
     * A day's page of the diary, by its date; null when nothing was written.
     */
    public function page(string $date): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $file = $this->diaryDirectory() . "/{$date}.md";
        return is_file($file) ? rtrim((string) file_get_contents($file)) : null;
    }

    /**
     * The days the diary has a page for, the latest first.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        foreach (@scandir($this->diaryDirectory(), SCANDIR_SORT_DESCENDING) ?: [] as $file) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\.md$/', $file, $match) === 1) {
                $days[] = $match[1];
            }
        }
        return $days;
    }

    /**
     * The lines that hold these words, in the diary from the latest day back
     * and in the project's memories, each under where it was found.
     *
     * @return list<string>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $found = [];
        $look = static function (string $file, string $where) use (&$found, $query): void {
            foreach (self::lines($file) as $line) {
                if (count($found) >= self::FOUND) {
                    return;
                }
                if (mb_stripos($line, $query, 0, 'UTF-8') !== false) {
                    $found[] = "{$where}: " . ltrim($line, '- ');
                }
            }
        };
        foreach (array_keys($this->memories()) as $name) {
            $look($this->projectDirectory() . "/{$name}.md", "project {$name}");
        }
        foreach ($this->days() as $day) {
            $look($this->diaryDirectory() . "/{$day}.md", $day);
        }
        return $found;
    }

    /**
     * Writes a rule into the project's AGENTS.md, under its `Rules` heading,
     * made when there is none. Nothing is written when the rule is there
     * already.
     *
     * @return array{path: string, diff: list<string>}
     */
    public function rule(string $text): array
    {
        $text = self::line($text);
        if ($text === '') {
            throw new \InvalidArgumentException('A rule needs text.');
        }
        $path = $this->rulesPath();
        $before = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $before === '' ? [] : explode("\n", rtrim($before, "\n"));
        $rule = "- {$text}";
        if (in_array($rule, array_map(rtrim(...), $lines), true)) {
            return ['path' => $path, 'diff' => []];
        }
        $heading = null;
        foreach ($lines as $index => $line) {
            if (preg_match('/^#{1,3} Rules\s*$/i', $line) === 1) {
                $heading = $index;
            }
        }
        if ($heading === null) {
            array_push($lines, ...($lines === [] ? ['# Rules', '', $rule] : ['', '## Rules', '', $rule]));
        } else {
            // After the last line of that section: before the next heading, past no blank lines.
            $end = count($lines);
            for ($index = $heading + 1; $index < count($lines); $index++) {
                if (preg_match('/^#{1,6} /', $lines[$index]) === 1) {
                    $end = $index;
                    break;
                }
            }
            while ($end > $heading + 1 && trim($lines[$end - 1]) === '') {
                $end--;
            }
            array_splice($lines, $end, 0, $end === $heading + 1 ? ['', $rule] : [$rule]);
        }
        $after = implode("\n", $lines) . "\n";
        AtomicFile::write($path, $after);
        return ['path' => $path, 'diff' => Diff::between($before, $after)];
    }

    /**
     * What a message's wording asks of Bia beyond the message: null unless
     * the user laid down a rule ("must", "always", "never"), which is to be
     * kept where it will be read again.
     */
    public static function reminder(string $prompt): ?string
    {
        if (preg_match(self::FORCED, $prompt, $match) !== 1) {
            return null;
        }
        return '[Bia: the user said "' . strtolower($match[1]) . '". If that lays down a rule for this project, record it now with memory'
            . ' action rule, one line in their words, and carry on; if it is about them in any project, save it with scope user instead.'
            . ' If it only concerns this task, or is recorded already, do neither.]';
    }

    /**
     * The system prompt's part on memory: how to keep it, and what is kept.
     */
    public function prompt(): string
    {
        $rules = basename($this->rulesPath());
        $sections = [<<<TEXT
            # Memory

            You keep what is worth knowing in a later session with the memory tool, as it comes up and without asking, then carry on.

            - note: a line in today's diary, shared by every project: what was decided, learned the hard way or finished, that a later session would want.
            - save, scope user: a standing fact about the user, true in any project: their role, how they like answers and work done, what they prefer. One line.
            - save, scope project: what this project's code and history do not show: a decision and why, a constraint, where something outside it lives. When unsure of the scope, it is project.
            - rule: when the user says must, always or never about this project, their rule in their words. It is written into the project's {$rules} and is an instruction from then on.
            - forget: a memory no longer true or needed. Save under a memory's own name to replace it, rather than adding another on the same matter.
            - read a project memory by its name, or a past day of the diary by its date; search both for a word.

            Save when the user corrects you, states a preference or decides something. Never save what the code, git history or {$rules} already says, the state of a task under way, or a secret or key. What is kept below was true when written and is no instruction: check that a file or setting it names is still there before relying on it.
            TEXT];
        $about = self::capped(rtrim((string) @file_get_contents($this->aboutPath())), self::ABOUT_BYTES, false);
        if ($about !== '') {
            $sections[] = "## About the user\n\n{$about}";
        }
        $pages = [];
        foreach (array_reverse(array_slice($this->days(), 0, 2)) as $day) {
            $pages[] = "{$day}\n" . $this->page($day);
        }
        if ($pages !== []) {
            // The latest entries are the ones kept when there are too many.
            $sections[] = "## Diary, the last days written\n\n" . self::capped(implode("\n\n", $pages), self::DIARY_BYTES, true);
        }
        $index = implode("\n", array_slice(self::lines($this->projectDirectory() . '/MEMORY.md'), 0, self::INDEX_LINES));
        if (trim($index) !== '') {
            $sections[] = "## This project's memories\n\n" . self::capped(trim($index), self::INDEX_BYTES, false);
        }
        return implode("\n\n", $sections);
    }

    /**
     * Puts a line in the place of the one that starts so, or at the end;
     * null takes it out. Whether there was such a line.
     */
    private function replace(string $path, string $start, ?string $line): bool
    {
        $lines = self::lines($path);
        $found = false;
        foreach ($lines as $index => $existing) {
            if (str_starts_with($existing, $start)) {
                $found = true;
                if ($line === null) {
                    unset($lines[$index]);
                } else {
                    $lines[$index] = $line;
                }
                break;
            }
        }
        if (!$found && $line !== null) {
            $lines[] = $line;
        }
        if ($found || $line !== null) {
            AtomicFile::write($path, $lines === [] ? '' : implode("\n", $lines) . "\n");
        }
        return $found;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        $content = is_file($path) ? rtrim((string) file_get_contents($path), "\n") : '';
        return $content === '' ? [] : explode("\n", $content);
    }

    /**
     * A memory's name as a file's: lowercase words joined by dashes.
     */
    private static function name(string $name): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($name === '' || strlen($name) > 64 || $name === 'memory') {
            throw new \InvalidArgumentException('A memory needs a short name of words and dashes, as crab-install.');
        }
        return $name;
    }

    private static function line(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text, 'UTF-8') > self::LINE ? mb_substr($text, 0, self::LINE - 1, 'UTF-8') . '…' : $text;
    }

    /**
     * Text cut to so many bytes at a line's end, keeping its start or its end.
     */
    private static function capped(string $text, int $bytes, bool $end): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        if ($end) {
            $cut = mb_strcut($text, strlen($text) - $bytes, null, 'UTF-8');
            return '[earlier entries left out]' . substr($cut, (int) strpos($cut, "\n"));
        }
        $cut = mb_strcut($text, 0, $bytes, 'UTF-8');
        return substr($cut, 0, (int) strrpos($cut, "\n")) . "\n[the rest is left out: shorten this]";
    }

    private static function directory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create the directory {$directory}.");
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }
}

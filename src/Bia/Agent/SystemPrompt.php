<?php

declare(strict_types=1);

namespace Bia\Agent;

use Bia\Tool\ShellTool;

/**
 * Builds the system prompt: who Bia is, how to use its tools, where it is
 * running, and the project's own instructions from AGENTS.md files.
 *
 * Instruction files are the user's global `AGENTS.md` beside their config,
 * then one per directory from the repository root down to the working
 * directory: `AGENTS.md`, or `CLAUDE.md` where there is none. Deeper files
 * come later, so they read as more specific. Together they are capped.
 */
final class SystemPrompt
{
    public const GUIDANCE = <<<'TEXT'
        You work in the user's project through tools, with full permission. Work autonomously: look things up instead of guessing, make the change, then check it.

        - Find code with search and list_files; read a file before you change it.
        - Change existing files with edit, copying old_text exactly from what you read; use write_file only for new files or full rewrites.
        - Run builds, tests and other commands with shell. Each call starts fresh at the workspace root: give workdir rather than cd. Prefer non-interactive flags; there is no terminal to answer prompts. Start servers and watchers with background: true and read them with shell_output.
        - Read-only tools can be called several at once in one turn.
        - Long output is cut; the note says where the full output was saved and read_file can page through it.
        - Keep answers short. Say what you changed and what you verified; do not paste files back.
        TEXT;

    /**
     * @param string $identity The configured system prompt, which leads.
     * @param string|null $globalDirectory Where the user's global AGENTS.md lives.
     */
    public static function build(string $identity, string $workspace, ?string $globalDirectory = null, int $maxInstructionBytes = 32_768): string
    {
        $sections = [];
        if (trim($identity) !== '') {
            $sections[] = trim($identity);
        }
        $sections[] = self::GUIDANCE;
        $sections[] = self::environment($workspace);
        $instructions = self::instructions($workspace, $globalDirectory, $maxInstructionBytes);
        if ($instructions !== '') {
            $sections[] = "# Project instructions\n\nFollow these instructions from the user and the project.\n\n" . $instructions;
        }
        return implode("\n\n", $sections);
    }

    /**
     * @return list<string> The instruction files that apply, in the order they are read.
     */
    public static function files(string $workspace, ?string $globalDirectory = null): array
    {
        $files = [];
        if ($globalDirectory !== null && is_file($globalDirectory . '/AGENTS.md')) {
            $files[] = $globalDirectory . '/AGENTS.md';
        }
        foreach (self::directories($workspace) as $directory) {
            foreach (['AGENTS.md', 'CLAUDE.md'] as $name) {
                if (is_file($directory . '/' . $name)) {
                    $files[] = $directory . '/' . $name;
                    break;
                }
            }
        }
        return $files;
    }

    private static function instructions(string $workspace, ?string $globalDirectory, int $maxBytes): string
    {
        $parts = [];
        $used = 0;
        foreach (self::files($workspace, $globalDirectory) as $file) {
            $content = trim((string) @file_get_contents($file));
            if ($content === '') {
                continue;
            }
            $label = str_starts_with($file, $workspace . '/') ? substr($file, strlen($workspace) + 1) : $file;
            $part = "## {$label}\n\n{$content}";
            if ($used + strlen($part) > $maxBytes) {
                $parts[] = mb_strcut($part, 0, max(0, $maxBytes - $used), 'UTF-8') . "\n[instructions cut at {$maxBytes} bytes]";
                break;
            }
            $parts[] = $part;
            $used += strlen($part);
        }
        return implode("\n\n", $parts);
    }

    /**
     * From the repository root (the nearest parent holding .git) down to the
     * workspace; just the workspace outside a repository.
     *
     * @return list<string>
     */
    private static function directories(string $workspace): array
    {
        $chain = [];
        $directory = $workspace;
        while (true) {
            array_unshift($chain, $directory);
            if (file_exists($directory . '/.git')) {
                return $chain;
            }
            $parent = dirname($directory);
            if ($parent === $directory) {
                return [$workspace];
            }
            $directory = $parent;
        }
    }

    private static function environment(string $workspace): string
    {
        $lines = [
            '# Environment',
            '',
            "- Working directory: {$workspace}",
            '- Platform: ' . PHP_OS_FAMILY . ' (' . php_uname('r') . ')',
            '- Shell: ' . basename(ShellTool::userShell()),
            '- Date: ' . date('Y-m-d'),
        ];
        $branch = self::branch($workspace);
        if ($branch !== null) {
            $lines[] = "- Git branch: {$branch}";
        }
        return implode("\n", $lines);
    }

    /**
     * The checked-out branch, read from .git/HEAD without starting git.
     */
    private static function branch(string $workspace): ?string
    {
        foreach (self::directories($workspace) as $directory) {
            $git = $directory . '/.git';
            if (is_file($git) && preg_match('/^gitdir: (.+)$/m', (string) @file_get_contents($git), $match) === 1) {
                $git = str_starts_with($match[1], '/') ? trim($match[1]) : $directory . '/' . trim($match[1]);
            }
            $head = @file_get_contents($git . '/HEAD');
            if (is_string($head)) {
                return preg_match('#^ref: refs/heads/(.+)$#', trim($head), $ref) === 1 ? $ref[1] : 'detached at ' . substr(trim($head), 0, 12);
            }
        }
        return null;
    }
}

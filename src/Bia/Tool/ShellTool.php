<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Runs a command in the user's shell (bash or zsh; bash, or sh, otherwise)
 * in the workspace.
 *
 * Each call is a fresh, non-interactive shell: at the workspace root, or a
 * workdir inside it; stdin empty; pagers, editors and git's prompts turned
 * off, so nothing waits for a terminal that is not there. The user's
 * aliases and functions are there, from a ShellSnapshot taken once, rather
 * than their profile read on every call, which costs tens of milliseconds
 * or more. Nothing carries over between calls, so calls stay independent.
 *
 * Output is standard output and error together, as a terminal shows them,
 * without colour codes or progress redrawn over itself. The agent cuts what
 * is long and saves it whole; the cap here only guards memory. A command
 * still running at its timeout is stopped; one meant to keep running goes
 * in the background, and shell_output reads it.
 */
final readonly class ShellTool implements Tool
{
    /** The most output kept: past it the command is stopped. Only a guard; the agent cuts long output. */
    public const MOST_BYTES = 16 * 1024 * 1024;

    /** The longest a command may be given, in seconds. */
    public const MOST_SECONDS = 600.0;

    /** Set for every command, so none waits on a terminal: pagers print, editors and prompts give up. */
    public const ENVIRONMENT = [
        'PAGER' => 'cat',
        'GIT_PAGER' => 'cat',
        'GIT_EDITOR' => 'true',
        'GIT_TERMINAL_PROMPT' => '0',
    ];

    private string $shell;

    public function __construct(
        private Workspace $workspace,
        private ProcessRunner $process,
        private float $defaultTimeout = 120.0,
        private ?Shells $shells = null,
        ?string $shell = null,
        private ?ShellSnapshot $snapshot = null,
    ) {
        $this->shell = $shell ?? self::findShell();
    }

    public function definition(): ToolDefinition
    {
        $shell = basename($this->shell);
        $timeout = (int) $this->defaultTimeout;
        $background = $this->shells !== null
            ? ' Anything meant to keep running (a server, a watcher) goes in the background: background: true starts it and returns at once with its number, and shell_output reads what it prints or stops it.'
            : '';
        $aliases = $this->snapshot !== null
            ? " The user's aliases and functions from their {$shell} config are there, as at their prompt: use one they name."
            : '';
        return new ToolDefinition('shell', <<<TEXT
            Run a command in {$shell} in the workspace, for builds, tests, git and other programs. Returns its output (standard output and error together) and, when it fails, its exit code.

            Each call is a fresh, non-interactive shell: it starts at the workspace root, or at workdir, with stdin empty; a cd and variables do not carry over to the next call, so give workdir instead of cd.{$aliases} Use read_file, search and list_files rather than cat, grep and ls. Pass non-interactive flags: nothing can answer a prompt. Long output is cut, and the note says where it was saved whole.

            A command is stopped after timeout seconds (default {$timeout}, at most 600).{$background}
            TEXT, [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string', 'description' => 'The command line, as typed at a prompt.'],
                'workdir' => ['type' => 'string', 'description' => 'Where to run it, relative to the workspace root. Default: the root.'],
                'timeout' => ['type' => 'number', 'minimum' => 1, 'maximum' => self::MOST_SECONDS, 'description' => "Seconds before it is stopped. Default {$timeout}."],
                ...($this->shells !== null ? ['background' => ['type' => 'boolean', 'description' => 'Start it and return at once, for a server or a watcher; read it with shell_output.']] : []),
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ], 'shell');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $cancellation?->throwIfCancelled();
        $command = trim((string) ($arguments['command'] ?? ''));
        if ($command === '') {
            return new ToolResult('The command is empty.', false);
        }
        $directory = $this->workspace->root();
        $workdir = trim((string) ($arguments['workdir'] ?? ''));
        if ($workdir !== '' && $workdir !== '.') {
            $directory = $this->workspace->path($workdir);
            if (!is_dir($directory)) {
                return new ToolResult("workdir {$workdir} is not a directory in the workspace.", false);
            }
        }
        if (($arguments['background'] ?? false) === true && $this->shells !== null) {
            // Its stdin empty from the start, as a command in the foreground has it.
            $id = $this->shells->start($this->argv("exec </dev/null\n", $command, $cancellation), $command, $directory, self::ENVIRONMENT);
            return new ToolResult("Started in the background as shell {$id}. Read its output with shell_output (id {$id}); stop it with kill: true.", true, ['background' => $id]);
        }
        $timeout = max(1.0, min(self::MOST_SECONDS, (float) ($arguments['timeout'] ?? $this->defaultTimeout)));
        $result = $this->process->run(
            $this->argv('', $command, $cancellation),
            $directory,
            $timeout,
            self::MOST_BYTES,
            cancellation: $cancellation,
            output: $context->output,
            environment: self::ENVIRONMENT,
        );
        $text = self::clean($result->output);
        $end = match (true) {
            $result->timedOut => "[stopped after {$timeout}s, its timeout"
                . ($this->shells !== null ? '; a server or watcher belongs in the background: true' : '') . ']',
            $result->truncated => '[stopped: its output passed ' . intdiv(self::MOST_BYTES, 1024 * 1024) . ' MB]',
            $result->exitCode === null => '[killed by a signal]',
            $result->exitCode !== 0 => "[exit code {$result->exitCode}]",
            default => null,
        };
        if ($end !== null) {
            $text .= ($text === '' || str_ends_with($text, "\n") ? '' : "\n") . $end;
        }
        return new ToolResult($text, $end === null, ['exit_code' => $result->exitCode]);
    }

    /**
     * Output as the model should read it: colour and other terminal codes
     * gone, and a line redrawn over itself, as a progress bar is, only as it
     * was last drawn.
     */
    public static function clean(string $output): string
    {
        $output = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)|\e[()][0-9A-Za-z]|\e[=>78]/', '', $output);
        $output = str_replace("\r\n", "\n", $output);
        if (str_contains($output, "\r")) {
            // Each line as last drawn: the last of its redraws with something in it.
            $output = implode("\n", array_map(static function (string $line): string {
                $drawn = array_values(array_filter(explode("\r", $line), static fn (string $part): bool => $part !== ''));
                return $drawn === [] ? '' : $drawn[count($drawn) - 1];
            }, explode("\n", $output)));
        }
        return $output;
    }

    /**
     * The shell and its arguments to run $command, after $before: in the
     * snapshot's aliases and functions when there is one, the command read
     * after it is sourced (by eval) so that they apply to it.
     *
     * @return list<string>
     */
    private function argv(string $before, string $command, ?Cancellation $cancellation): array
    {
        $file = $this->snapshot?->file($cancellation);
        if ($file === null) {
            return [$this->shell, '-c', $before . $command];
        }
        $quote = static fn (string $text): string => "'" . str_replace("'", "'\\''", $text) . "'";
        return [$this->shell, '-c', $before . '. ' . $quote($file) . " 2>/dev/null\neval " . $quote($command)];
    }

    /**
     * The user's shell, from SHELL, where a snapshot can be taken in it
     * (bash or zsh); bash, or sh, otherwise.
     */
    public static function userShell(): string
    {
        $shell = (string) getenv('SHELL');
        return str_starts_with($shell, '/') && ShellSnapshot::supports($shell) && is_executable($shell) ? $shell : self::findShell();
    }

    /**
     * bash, where it is; sh otherwise.
     */
    private static function findShell(): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_file($directory . '/bash') && is_executable($directory . '/bash')) {
                return $directory . '/bash';
            }
        }
        return is_executable('/bin/bash') ? '/bin/bash' : '/bin/sh';
    }
}

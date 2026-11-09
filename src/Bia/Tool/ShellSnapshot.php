<?php

declare(strict_types=1);

namespace Bia\Tool;

use Crab\Engine\Async\Cancellation;

/**
 * The user's shell as their terminal has it: the aliases and functions their
 * rc files define, taken once from an interactive shell into a file that each
 * command sources, so `ssh.work` or a `gco` function works as it does at their
 * prompt without paying for their profile on every call.
 *
 * Taken on first use and again by a new one (as `/reload` makes); a shell that
 * fails or hangs while starting gives no snapshot, and commands run without it.
 * Completion functions (`_git`) are left out: they need what only an
 * interactive shell has. The file is the user's alone and goes when Bia ends.
 */
final class ShellSnapshot
{
    /** The longest the user's shell may take to start, in seconds. */
    public const MOST_SECONDS = 10.0;

    private ?string $file = null;
    private bool $taken = false;

    /**
     * @param array<string, string> $environment Variables over the runner's, as a test gives HOME.
     */
    public function __construct(
        private readonly string $shell,
        private readonly ProcessRunner $runner,
        private readonly array $environment = [],
        private readonly float $timeout = self::MOST_SECONDS,
    ) {}

    /**
     * Whether a snapshot can be taken in this shell: bash and zsh.
     */
    public static function supports(string $shell): bool
    {
        return in_array(basename($shell), ['bash', 'zsh'], true);
    }

    /**
     * The snapshot's path, taking it the first time; null when there is none.
     */
    public function file(?Cancellation $cancellation = null): ?string
    {
        if ($this->taken) {
            return $this->file;
        }
        $this->taken = true;
        if (!self::supports($this->shell)) {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'bia-shell-');
        if ($file === false) {
            return null;
        }
        $zsh = basename($this->shell) === 'zsh';
        try {
            // Interactive, so the rc files are read; zsh as a login shell too, as a terminal starts it.
            $this->runner->run(
                [$this->shell, ...($zsh ? ['-l', '-i'] : ['-i']), '-c', $zsh ? self::ZSH : self::BASH, 'bia', $file],
                (string) (getenv('HOME') ?: sys_get_temp_dir()),
                $this->timeout,
                64 * 1024,
                cancellation: $cancellation,
                environment: ['BIA' => '1', ...$this->environment],
            );
        } catch (\Throwable) {
            // Cancelled, or the shell could not start: no snapshot this time.
            @unlink($file);
            $this->taken = false;
            return null;
        }
        if (!str_contains((string) @file_get_contents($file, length: 64), '# bia shell snapshot')) {
            @unlink($file);
            return null;
        }
        $this->file = $file;
        register_shutdown_function(static fn () => @unlink($file));
        return $file;
    }

    public function __destruct()
    {
        if ($this->file !== null) {
            @unlink($this->file);
        }
    }

    /** Writes the marker, then the functions (but completions) and aliases, to $1. */
    private const ZSH = <<<'SH'
        {
          print -r -- '# bia shell snapshot'
          for f in ${(k)functions:#_[^_]*}; do typeset -f -- "$f"; done
          print -r -- 'setopt aliases 2>/dev/null'
          alias -L
        } >| "$1" 2>/dev/null
        SH;

    private const BASH = <<<'SH'
        {
          echo '# bia shell snapshot'
          for f in $(compgen -A function); do case $f in _[!_]*) ;; *) declare -f -- "$f" ;; esac; done
          echo 'shopt -s expand_aliases'
          alias -p
        } > "$1" 2>/dev/null
        SH;
}

<?php

declare(strict_types=1);

namespace Bia\Tui;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;

/**
 * The controlling terminal: its size, raw mode and keys.
 *
 * Raw mode is entered with `stty` on /dev/tty, synchronously: it is two short
 * commands at the start and end of a session, not tool work, so it does not
 * go through the event loop. Whatever happens, the terminal is given back as
 * it was found, on exit too.
 */
final class Terminal
{
    private ?string $saved = null;
    private string $buffer = '';
    private ?int $columns = null;
    private ?int $rows = null;
    private bool $alternate = false;

    /**
     * @param resource $input
     * @param resource $output
     * @param array{0: int, 1: int}|null $size Columns and rows, fixed; null to measure the terminal.
     */
    public function __construct(
        private readonly mixed $input = STDIN,
        private readonly mixed $output = STDOUT,
        private readonly ?array $size = null,
    ) {}

    public static function interactive(): bool
    {
        return function_exists('stream_isatty') && stream_isatty(STDIN) && stream_isatty(STDOUT) && stream_isatty(STDERR)
            && getenv('TERM') !== 'dumb';
    }

    public function write(string $bytes): void
    {
        $length = strlen($bytes);
        for ($written = 0; $written < $length;) {
            $count = @fwrite($this->output, $written === 0 ? $bytes : substr($bytes, $written));
            if ($count === false || $count === 0) {
                return; // The terminal went away; there is no one to show it to.
            }
            $written += $count;
        }
    }

    public function columns(): int
    {
        $this->measure();
        return $this->columns;
    }

    public function rows(): int
    {
        $this->measure();
        return $this->rows;
    }

    /**
     * Forgets the size, to be measured again on next use, as after a resize.
     */
    public function resized(): void
    {
        $this->columns = $this->rows = null;
    }

    /**
     * Raw mode: every key comes as it is pressed, with no echo, and Ctrl-C
     * and Ctrl-Z come as keys instead of signals. Pastes come bracketed.
     */
    public function enter(): void
    {
        if ($this->saved !== null) {
            return;
        }
        $saved = trim((string) shell_exec('stty -g < /dev/tty 2>/dev/null'));
        if ($saved === '') {
            throw new \RuntimeException('Bia could not take over the terminal.');
        }
        $this->saved = $saved;
        shell_exec('stty -icanon -echo -isig -iexten -icrnl -ixon min 1 time 0 < /dev/tty 2>/dev/null');
        stream_set_blocking($this->input, false);
        $this->write("\e[?2004h");
        register_shutdown_function($this->leave(...));
    }

    /**
     * The alternate screen, with the mouse reported as keys (the wheel, and
     * presses, drags and releases for selecting): the whole terminal for the
     * program, given back as it was on leaving.
     */
    public function alternate(bool $on): void
    {
        if ($on === $this->alternate) {
            return;
        }
        $this->alternate = $on;
        $this->write($on ? "\e[?1049h\e[H\e[2J\e[?1002h\e[?1006h" : "\e[?1006l\e[?1002l\e[?1000l\e[?1049l");
    }

    /**
     * Puts text on the clipboard through the terminal itself (OSC 52), which
     * works over SSH too, where the terminal allows it.
     */
    public function copy(string $text): void
    {
        // Ended by ST, the standard end, which a multiplexer in between passes on more surely than BEL.
        $this->write("\e]52;c;" . base64_encode($text) . "\e\\");
    }

    /**
     * Tells the terminal what the program is doing (OSC 7501, the Program
     * Status Protocol): idle, working, done, blocked or error. Terminals that
     * do not know it ignore it.
     *
     * @param array<string, string> $fields More, as app or kind.
     */
    public function status(string $state, array $fields = []): void
    {
        $record = 'state=' . $state;
        foreach ($fields as $key => $value) {
            // A field ends at a colon; nothing in it may end the sequence.
            $record .= ':' . $key . '=' . preg_replace('/[\x00-\x1f\x7f:]/', ' ', $value);
        }
        $this->write("\e]7501;{$record}\e\\");
    }

    public function leave(): void
    {
        if ($this->saved === null) {
            return;
        }
        $this->alternate(false);
        $this->write("\e[?2004l\e[?25h");
        shell_exec('stty ' . escapeshellarg($this->saved) . ' < /dev/tty 2>/dev/null');
        stream_set_blocking($this->input, true);
        $this->saved = null;
    }

    /**
     * Waits on the event loop for the next key.
     *
     * @return Key|null Null when the input has closed.
     */
    public function key(?Cancellation $cancellation = null): ?Key
    {
        $loop = EventLoop::current();
        while (true) {
            if (($key = Keys::take($this->buffer)) !== null) {
                return $key;
            }
            $chunk = @fread($this->input, 8192);
            if ($chunk !== false && $chunk !== '') {
                $this->buffer .= $chunk;
                continue;
            }
            if (feof($this->input)) {
                return null;
            }
            // Nothing yet, or the start of a character or a paste: wait for more.
            $suspension = $loop->getSuspension();
            $watcher = $loop->onReadable($this->input, static fn () => $suspension->resume());
            $unsubscribe = $cancellation?->subscribe(static fn (\Throwable $error) => $suspension->throw($error));
            try {
                $suspension->suspend();
            } finally {
                $loop->cancel($watcher);
                if ($unsubscribe !== null) {
                    $unsubscribe();
                }
            }
        }
    }

    private function measure(): void
    {
        if ($this->columns !== null) {
            return;
        }
        if ($this->size !== null) {
            [$this->columns, $this->rows] = $this->size;
            return;
        }
        $size = trim((string) shell_exec('stty size < /dev/tty 2>/dev/null'));
        [$rows, $columns] = array_map('intval', explode(' ', $size . ' 0'));
        $this->columns = $columns > 0 ? $columns : max(20, (int) (getenv('COLUMNS') ?: 80));
        $this->rows = $rows > 0 ? $rows : max(10, (int) (getenv('LINES') ?: 24));
    }
}

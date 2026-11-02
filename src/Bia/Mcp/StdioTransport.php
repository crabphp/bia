<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Bia\Tool\ProcessRunner;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Process;

/**
 * A server started here, talked to over its standard input and output: one
 * JSON message a line each way. What it writes on standard error is kept,
 * the last of it, to say why it failed.
 */
final class StdioTransport implements Transport
{
    private ?Process $process = null;
    private string $buffer = '';
    private string $errors = '';

    public function __construct(
        private readonly Server $server,
        private readonly ProcessRunner $runner,
        private readonly string $cwd,
    ) {}

    public function open(\Closure $receive, \Closure $closed): void
    {
        $process = $this->runner->start([$this->server->command, ...$this->server->args], $this->cwd, $this->server->env);
        $this->process = $process;
        $process->onOutput(function (string $chunk) use ($receive): void {
            $this->buffer .= $chunk;
            while (($end = strpos($this->buffer, "\n")) !== false) {
                $line = trim(substr($this->buffer, 0, $end));
                $this->buffer = substr($this->buffer, $end + 1);
                $message = $line === '' ? null : json_decode($line, true);
                if (is_array($message)) {
                    $receive($message); // anything else on its output is the server's own noise
                }
            }
        });
        $process->onError(function (string $chunk): void {
            $this->errors = substr($this->errors . $chunk, -2000);
        });
        $process->onExit(function (?int $code) use ($closed): void {
            $last = trim((string) strrchr("\n" . trim($this->errors), "\n"));
            $closed(($code === null ? 'it was stopped' : "it exited ({$code})") . ($last !== '' ? ": {$last}" : ''));
        });
    }

    public function send(array $message): void
    {
        $this->process?->write(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    }

    /**
     * Ends the server: its input is closed, which a server takes as its cue
     * to stop; one still running a moment later is killed, with what it started.
     */
    public function close(): void
    {
        $process = $this->process;
        $this->process = null;
        if ($process === null) {
            return;
        }
        $process->stop(grace: 0.3);
        $loop = EventLoop::current();
        $until = microtime(true) + 0.4;
        while ($process->running() && microtime(true) < $until) {
            $suspension = $loop->getSuspension();
            $loop->delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
        }
        if ($process->running()) {
            $process->kill();
        }
    }
}

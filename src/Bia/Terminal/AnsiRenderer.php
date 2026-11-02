<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;

/**
 * Small streaming renderer for the first Bia CLI. It does not own the agent
 * loop and remains usable when stdout is redirected.
 */
final class AnsiRenderer implements Renderer
{
    private bool $started = false;
    private readonly bool $color;

    public function __construct(private readonly bool $json = false)
    {
        $this->color = !$json && function_exists('stream_isatty') && stream_isatty(STDERR) && getenv('NO_COLOR') === false;
    }

    public function event(ModelEvent $event): void
    {
        if ($this->json) {
            $this->line(json_encode([
                'type' => $event->type,
                'text' => $event->text,
                'data' => $event->data,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($event->type === 'text_delta') {
            if (!$this->started) {
                $this->started = true;
            }
            fwrite(STDOUT, $event->text);
            fflush(STDOUT);
            return;
        }
    }

    public function notice(string $message): void
    {
        if ($this->json) {
            $this->line(json_encode(['type' => 'notice', 'text' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($this->started) {
            fwrite(STDOUT, PHP_EOL);
            $this->started = false;
        }
        fwrite(STDERR, $this->paint('2', "· {$message}") . PHP_EOL);
    }

    public function running(ToolCall $call, string $output): void
    {
        // A finished call is shown whole; a script reads the result, not a stream.
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        if ($this->json) {
            $this->line(json_encode([
                'type' => 'tool_result',
                'id' => $call->id,
                'name' => $call->name,
                'arguments' => $call->arguments,
                'ok' => $result->ok,
                'text' => mb_strcut($result->text, 0, 4096, 'UTF-8'),
                'bytes' => strlen($result->text),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            return;
        }
        if ($this->started) {
            fwrite(STDOUT, PHP_EOL);
            $this->started = false;
        }
        $mark = $result->ok ? $this->paint('32', '✓') : $this->paint('31', '✗');
        $line = "{$mark} {$call->name}";
        $subject = self::subject($call->arguments);
        if ($subject !== '') {
            $line .= ' ' . $this->paint('2', $subject);
        }
        fwrite(STDERR, $line . PHP_EOL);
        if (!$result->ok || $call->name === 'shell') {
            $lines = array_slice(preg_split('/\R/', rtrim($result->text)) ?: [], -3);
            foreach ($lines as $output) {
                if ($output !== '') {
                    fwrite(STDERR, '  ' . $this->paint('2', mb_strimwidth($output, 0, 160, '…', 'UTF-8')) . PHP_EOL);
                }
            }
        }
    }

    /**
     * The argument that says what a call was about, on one short line.
     *
     * @param array<string, mixed> $arguments
     */
    public static function subject(array $arguments): string
    {
        foreach (['command', 'path', 'pattern'] as $key) {
            if (isset($arguments[$key]) && is_string($arguments[$key])) {
                $value = (string) preg_replace('/\s+/', ' ', $arguments[$key]);
                return mb_strimwidth($value, 0, 120, '…', 'UTF-8');
            }
        }
        return '';
    }

    private function paint(string $code, string $text): string
    {
        return $this->color ? "\e[{$code}m{$text}\e[0m" : $text;
    }

    public function finish(ModelResult $result): void
    {
        if ($this->json) {
            $this->line(json_encode([
                'type' => 'result',
                'text' => $result->text,
                'finish_reason' => $result->finishReason,
                'usage' => $result->usage,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($this->started) {
            fwrite(STDOUT, PHP_EOL);
        }
    }

    public function error(string $message): void
    {
        fwrite(STDERR, "bia: {$message}" . PHP_EOL);
    }

    private function line(string $line): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }
}

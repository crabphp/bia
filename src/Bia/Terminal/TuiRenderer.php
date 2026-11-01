<?php

declare(strict_types=1);

namespace Bia\Terminal;

use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolResult;
use Bia\Tui\Region;
use Bia\Tui\Style;

/**
 * Shows a turn in the conversation's screen: answer text as it streams,
 * a line per tool call, and what the agent is doing on the spinner.
 */
final class TuiRenderer implements Renderer
{
    /** @var \Closure(string): void */
    private \Closure $activity;
    /** @var \Closure(array<string, mixed>): void */
    private \Closure $usage;

    public function __construct(private readonly Region $region)
    {
        $this->activity = static function (string $label): void {};
        $this->usage = static function (array $usage): void {};
    }

    /**
     * @param \Closure(string): void $activity Told what the agent is doing.
     * @param \Closure(array<string, mixed>): void $usage Told the token usage of each request.
     */
    public function listen(\Closure $activity, \Closure $usage): void
    {
        $this->activity = $activity;
        $this->usage = $usage;
    }

    public function event(ModelEvent $event): void
    {
        switch ($event->type) {
            case 'text_delta':
                ($this->activity)('Writing');
                $this->region->print($event->text);
                return;
            case 'reasoning_delta':
                ($this->activity)('Thinking');
                return;
            case 'tool_call':
                $name = is_string($event->data['name'] ?? null) ? $event->data['name'] : 'a tool';
                ($this->activity)("Running {$name}");
                return;
            case 'usage':
                ($this->usage)($event->data);
                return;
        }
    }

    public function tool(ToolCall $call, ToolResult $result): void
    {
        $this->lineStart();
        $line = ($result->ok ? Style::ok('✓') : Style::error('✗')) . ' ' . $call->name;
        $subject = AnsiRenderer::subject($call->arguments);
        if ($subject !== '') {
            $line .= ' ' . Style::dim($subject);
        }
        $this->region->print($line . "\n");
        if (!$result->ok || $call->name === 'shell') {
            foreach (array_slice(preg_split('/\R/', rtrim($result->text)) ?: [], -3) as $output) {
                if ($output !== '') {
                    $this->region->print('  ' . Style::dim(Style::truncate($output, 160)) . "\n");
                }
            }
        }
        ($this->activity)('Thinking');
    }

    public function notice(string $message): void
    {
        $this->lineStart();
        $this->region->print(Style::dim("· {$message}") . "\n");
    }

    public function finish(ModelResult $result): void
    {
        $this->lineStart();
    }

    private function lineStart(): void
    {
        if (!$this->region->atLineStart()) {
            $this->region->print("\n");
        }
    }
}

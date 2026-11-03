<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Reads a background command: what it printed since it was last read,
 * whether it still runs, and how it ended; or stops it.
 */
final readonly class ShellOutputTool implements Tool
{
    /** The longest a read waits for output, in seconds. */
    private const MOST_WAIT = 30.0;

    public function __construct(private Shells $shells) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('shell_output', 'Read a command started with shell and background: true: what it printed since it was last read, and whether it still runs. wait waits for something new, or for it to end; kill: true stops it and what it started.', [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The number shell gave it.'],
                'wait' => ['type' => 'number', 'minimum' => 0, 'maximum' => self::MOST_WAIT, 'description' => 'Seconds to wait for new output or for it to end. Default 0.'],
                'kill' => ['type' => 'boolean', 'description' => 'Stop it.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ], 'shell');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $id = (int) ($arguments['id'] ?? 0);
        if (($arguments['kill'] ?? false) === true) {
            if (!$this->shells->stop($id)) {
                return new ToolResult("There is no background shell {$id}.", false);
            }
            $wait = 1.0; // a moment to end, and say how
        } else {
            $wait = max(0.0, min(self::MOST_WAIT, (float) ($arguments['wait'] ?? 0.0)));
        }
        $read = $this->shells->read($id, $wait, $cancellation);
        if ($read === null) {
            $running = $this->shells->running();
            return new ToolResult("There is no background shell {$id}." . ($running !== [] ? ' Running: ' . implode(', ', $running) . '.' : ''), false);
        }
        $status = match (true) {
            $read['running'] => "[shell {$id} is still running]",
            $read['exit'] === null => "[shell {$id} was stopped]",
            default => "[shell {$id} exited with code {$read['exit']}]",
        };
        $output = ShellTool::clean($read['output']);
        $text = ($read['dropped'] > 0 ? "[… {$read['dropped']} earlier bytes were not kept]\n" : '')
            . ($output === '' ? '(nothing new)' : rtrim($output, "\n")) . "\n" . $status;
        return new ToolResult($text, true, ['background' => $id, 'running' => $read['running'], 'exit_code' => $read['exit']]);
    }
}

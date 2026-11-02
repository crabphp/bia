<?php

declare(strict_types=1);

namespace Bia\Tool;

use Bia\Contracts\Tool;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Crab\Engine\Async\Cancellation;

/**
 * Runs a shell command in the workspace. Not a login shell: loading the
 * user's profile costs tens of milliseconds a call and varies by machine.
 */
final readonly class ShellTool implements Tool
{
    public function __construct(
        private Workspace $workspace,
        private ProcessRunner $process,
        private float $defaultTimeout = 120.0,
        private int $maxBytes = 1_048_576,
    ) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('shell', 'Run a shell command in the workspace.', [
            'type' => 'object',
            'properties' => [
                'command' => ['type' => 'string'],
                'timeout' => ['type' => 'number', 'minimum' => 0.1, 'maximum' => 600, 'description' => 'Seconds before the command is killed.'],
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
            return new ToolResult('Shell command must not be empty.', false);
        }
        $timeout = max(0.1, min(600.0, (float) ($arguments['timeout'] ?? $this->defaultTimeout)));
        $result = $this->process->run(['/bin/sh', '-c', $command], $this->workspace->root(), $timeout, $this->maxBytes, cancellation: $cancellation, output: $context->output);
        if ($result->timedOut) {
            return new ToolResult($result->output . "\n[command timed out after {$timeout}s]", false, ['exit_code' => null]);
        }
        if ($result->truncated) {
            return new ToolResult($result->output . "\n[output passed {$this->maxBytes} bytes; command stopped]", false, ['exit_code' => null]);
        }
        $text = $result->output;
        if (!$result->ok()) {
            $text .= ($text === '' || str_ends_with($text, "\n") ? '' : "\n") . "[exit code {$result->exitCode}]";
        }
        return new ToolResult($text, $result->ok(), ['exit_code' => $result->exitCode]);
    }
}

<?php

declare(strict_types=1);

namespace Bia\Contracts;

use Crab\Engine\Async\Cancellation;

/**
 * A coding tool exposed to a model.
 */
interface Tool
{
    public function definition(): ToolDefinition;

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult;
}

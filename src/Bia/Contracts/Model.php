<?php

declare(strict_types=1);

namespace Bia\Contracts;

use Crab\Engine\Async\Cancellation;

/**
 * Provider-neutral model interface. Adapters emit normalized events and never
 * expose a provider's wire format to the agent loop.
 */
interface Model
{
    /**
     * @param callable(ModelEvent): void $emit
     */
    public function stream(
        ModelRequest $request,
        callable $emit,
        ?Cancellation $cancellation = null,
    ): ModelResult;
}

<?php

declare(strict_types=1);

namespace Bia\Model;

use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Crab\Engine\Async\Cancellation;

/**
 * A model that drops an option its provider refuses, as a model without
 * reasoning refuses `reasoning_effort`, and sends the request again without
 * it: once, and never again to this model, so a refusal costs one request
 * and not one a step.
 */
final class Lenient implements Model
{
    /** @var array<string, true> Options the provider refused. */
    private array $refused = [];

    /**
     * @param \Closure(string): void $notice Told when an option is dropped.
     */
    public function __construct(
        private readonly Model $model,
        private readonly \Closure $notice,
    ) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        while (true) {
            $options = array_diff_key($request->options, $this->refused);
            try {
                return $this->model->stream(
                    $options === $request->options ? $request : new ModelRequest($request->model, $request->messages, $request->tools, $options),
                    $emit,
                    $cancellation,
                );
            } catch (ModelException $error) {
                $field = array_find(array_keys($options), static fn (string $key): bool => $error->rejects($key));
                if ($field === null) {
                    throw $error;
                }
                $this->refused[$field] = true;
                ($this->notice)("{$request->model} takes no {$field}; sent without it");
            }
        }
    }
}

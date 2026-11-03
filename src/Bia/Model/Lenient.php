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
 *
 * So too with the reasoning sent back with earlier replies: a provider that
 * wants it on every reply (DeepSeek, with tools) is sent it on all, an empty
 * one where none was kept; one that refuses it is sent none.
 */
final class Lenient implements Model
{
    /** @var array<string, true> Options the provider refused. */
    private array $refused = [];

    /** How reasoning goes back to this provider, once it said: see ModelRequest::$reasoning. */
    private ?string $reasoning = null;

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
            $sent = $request->with(array_diff_key($request->options, $this->refused), reasoning: $this->reasoning ?? $request->reasoning);
            try {
                return $this->model->stream($sent, $emit, $cancellation);
            } catch (ModelException $error) {
                $field = array_find(array_keys($sent->options), static fn (string $key): bool => $error->rejects($key));
                if ($field !== null) {
                    $this->refused[$field] = true;
                    ($this->notice)("{$request->model} takes no {$field}; sent without it");
                    continue;
                }
                $next = $error->rejects('reasoning') ? self::nextReasoning($sent) : null;
                if ($next === null) {
                    throw $error;
                }
                $this->reasoning = $next;
                ($this->notice)($next === 'all'
                    ? "{$request->model} wants its reasoning back on every reply; sent"
                    : "{$request->model} takes no reasoning back; sent without it");
            }
        }
    }

    /**
     * After a refusal about reasoning: when some reply went without it, the
     * provider wants it on all; when it was on every one, or already sent on
     * all, it wants none. Null when none was sent already.
     */
    private static function nextReasoning(ModelRequest $request): ?string
    {
        if ($request->reasoning === 'none') {
            return null;
        }
        if ($request->reasoning === 'all') {
            return 'none';
        }
        foreach ($request->messages as $message) {
            if ($message->role === 'assistant' && ($message->reasoning === '' || ($message->meta['reasoning_field'] ?? '') === '')) {
                return 'all';
            }
        }
        return 'none';
    }
}

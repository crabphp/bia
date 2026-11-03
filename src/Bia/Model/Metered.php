<?php

declare(strict_types=1);

namespace Bia\Model;

use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Crab\Engine\Async\Cancellation;

/**
 * One provider's model, each answer from it counted for `/usage`: when,
 * which model, its tokens, and how long its first token and its whole took.
 * Only that: never a prompt, an answer, a path or a key, and nothing for a
 * request that failed.
 *
 * It wraps the provider itself, under retries and fallbacks, so the model
 * counted is the one that answered.
 */
final readonly class Metered implements Model
{
    /**
     * @param \Closure(array{at: float, model: string, input: int, cached: int, output: int, first_ms: ?int, total_ms: int}): void $record
     */
    public function __construct(
        private Model $model,
        private string $label,
        private \Closure $record,
    ) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $started = microtime(true);
        $first = null;
        $result = $this->model->stream($request, static function ($event) use (&$first, $emit): void {
            $first ??= microtime(true);
            $emit($event);
        }, $cancellation);
        try {
            ($this->record)([
                'at' => $started,
                'model' => $this->label,
                'input' => (int) ($result->usage['input'] ?? 0),
                'cached' => (int) ($result->usage['cache_read'] ?? 0),
                'output' => (int) ($result->usage['output'] ?? 0),
                'first_ms' => $first === null ? null : (int) round(($first - $started) * 1000),
                'total_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable) {
            // Usage is kept as it can be: an answer is never lost for it.
        }
        return $result;
    }
}

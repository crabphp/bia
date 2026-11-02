<?php

declare(strict_types=1);

namespace Bia\Model;

use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Crab\Engine\Async\Cancellation;
use Crab\HttpClient\NetworkException;

/**
 * The chosen model, then others to fall back on, in order.
 *
 * A model whose provider fails before any of its answer has arrived (a rate
 * limit, an outage, a key refused, the connection lost) rests for a
 * cooldown, and the same request goes at once to the next. The first model
 * not resting is always asked first, so the chosen one is tried again when
 * its cooldown ends, and kept resting if it fails again. With every model
 * resting, each is tried anyway, the soonest back first; the last failure is
 * thrown only when none answers.
 */
final class Fallback implements Model
{
    /** @var array<int, float> When each resting model may be asked again, by position. */
    private array $resting = [];
    private int $current = 0;

    /**
     * @param non-empty-list<array{name: string, label: string, model: Model}> $models The chosen model first.
     * @param \Closure(string): void $notice Told when another model answers.
     * @param (\Closure(): float)|null $clock
     */
    public function __construct(
        private readonly array $models,
        private readonly \Closure $notice,
        private readonly float $cooldown = 300.0,
        private readonly ?\Closure $clock = null,
    ) {
        if ($models === [] || !is_finite($cooldown) || $cooldown < 0) {
            throw new \InvalidArgumentException('A fallback needs a model and a cooldown of zero seconds or more.');
        }
    }

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $failure = null;
        $why = '';
        foreach ($this->order() as $index) {
            $entry = $this->models[$index];
            $started = false;
            try {
                $result = $entry['model']->stream(
                    new ModelRequest($entry['name'], $request->messages, $request->tools, $request->options),
                    static function ($event) use (&$started, $emit): void {
                        $started = true;
                        $emit($event);
                    },
                    $cancellation,
                );
            } catch (ModelException | NetworkException $error) {
                if ($started || !self::passes($error)) {
                    throw $error;
                }
                $this->resting[$index] = $this->now() + $this->cooldown;
                $failure = $error;
                $why = $entry['label'] . ': ' . ($error instanceof ModelException ? $error->status : 'connection failed');
                continue;
            }
            unset($this->resting[$index]);
            if ($index !== $this->current) {
                ($this->notice)(($why !== '' ? "{$why} · " : '') . '→ ' . $entry['label']);
                $this->current = $index;
            }
            return new ModelResult($result->text, $result->finishReason, $result->usage, $result->toolCalls, $result->raw, $result->reasoning, $index === 0 ? '' : $entry['label']);
        }
        throw $failure ?? new \LogicException('No model was asked.');
    }

    /**
     * Positions in the order they are asked: those not resting as listed,
     * then the resting ones, the soonest back first.
     *
     * @return list<int>
     */
    private function order(): array
    {
        $now = $this->now();
        $ready = [];
        $resting = [];
        foreach (array_keys($this->models) as $index) {
            $until = $this->resting[$index] ?? 0.0;
            if ($until <= $now) {
                $ready[] = $index;
            } else {
                $resting[$index] = $until;
            }
        }
        asort($resting);
        return [...$ready, ...array_keys($resting)];
    }

    /**
     * Whether another provider may do better: this one is limited, down or
     * refusing the key. A request the provider found wrong, or too long, would
     * be wrong anywhere.
     */
    private static function passes(ModelException | NetworkException $error): bool
    {
        return $error instanceof NetworkException
            || $error->transient()
            || in_array($error->status, [401, 402, 403], true);
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}

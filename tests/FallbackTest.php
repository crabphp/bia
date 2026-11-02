<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Model\Fallback;
use Bia\Model\ModelException;
use Crab\Engine\Async\Cancellation;
use PHPUnit\Framework\TestCase;

final class FallbackTest extends TestCase
{
    private float $now = 1000.0;
    /** @var list<string> */
    private array $notices = [];
    /** @var list<string> Which model was asked, in order. */
    private array $asked = [];

    public function testAFailedProviderPassesTheRequestOnAtOnce(): void
    {
        $fallback = $this->fallback(['opus' => [new ModelException('slow down', 429)], 'gpt' => []]);

        $result = $fallback->stream(new ModelRequest('opus', [Message::user('hi')]), static function (): void {});

        self::assertSame('answer from gpt', $result->text);
        self::assertSame('openai/gpt', $result->model, 'The session records who answered.');
        self::assertSame(['opus', 'gpt'], $this->asked, 'Each asked by its own name.');
        self::assertSame(['anthropic/opus: 429 · → openai/gpt'], $this->notices);
    }

    public function testAFailedModelRestsThenIsAskedFirstAgain(): void
    {
        $fallback = $this->fallback(['opus' => [new ModelException('down', 503)], 'gpt' => []]);
        $ask = fn () => $fallback->stream(new ModelRequest('opus', []), static function (): void {});

        $ask();
        $this->now += 299;
        $ask();
        self::assertSame(['opus', 'gpt', 'gpt'], $this->asked, 'Resting, it is not asked.');

        $this->now += 2;
        $result = $ask();
        self::assertSame(['opus', 'gpt', 'gpt', 'opus'], $this->asked, 'Its cooldown over, it is asked first.');
        self::assertSame('', $result->model, 'The chosen model answered.');
        self::assertSame(['anthropic/opus: 503 · → openai/gpt', '→ anthropic/opus'], $this->notices);
    }

    public function testAModelFailingAgainRestsAgainQuietly(): void
    {
        $fallback = $this->fallback(['opus' => [new ModelException('down', 503), new ModelException('down', 503)], 'gpt' => []]);
        $ask = fn () => $fallback->stream(new ModelRequest('opus', []), static function (): void {});

        $ask();
        $this->now += 301;
        $ask();
        $ask();

        self::assertSame(['opus', 'gpt', 'opus', 'gpt', 'gpt'], $this->asked, 'The working fallback is kept.');
        self::assertCount(1, $this->notices, 'Staying on the same fallback says nothing new.');
    }

    public function testARequestWrongAnywhereIsNotPassedOn(): void
    {
        $fallback = $this->fallback(['opus' => [new ModelException('bad request', 400)], 'gpt' => []]);

        try {
            $fallback->stream(new ModelRequest('opus', []), static function (): void {});
            self::fail('A 400 is thrown.');
        } catch (ModelException $error) {
            self::assertSame(400, $error->status);
        }
        self::assertSame(['opus'], $this->asked);
    }

    public function testARoleLostByAProxyIsNotPassedOn(): void
    {
        $garbled = ModelException::fromAnswer(400, '{"error":{"message":"Invalid value: \'\'. Supported values are: \'assistant\', \'system\'."}}');
        $fallback = $this->fallback(['opus' => [$garbled], 'gpt' => []]);

        try {
            $fallback->stream(new ModelRequest('opus', []), static function (): void {});
            self::fail('It is thrown, for the agent to try the same model again.');
        } catch (ModelException $error) {
            self::assertTrue($error->garbled());
        }
        self::assertSame(['opus'], $this->asked, 'Not a provider down: no other model, no rest.');
    }

    public function testAnAnswerCutOffPartwayIsNotPassedOn(): void
    {
        $fallback = $this->fallback(['opus' => [[new ModelException('broke', 500), 'half']], 'gpt' => []]);
        $text = '';

        $this->expectException(ModelException::class);
        try {
            $fallback->stream(new ModelRequest('opus', []), static function (ModelEvent $event) use (&$text): void {
                $text .= $event->data['text'] ?? '';
            });
        } finally {
            self::assertSame(['opus'], $this->asked, 'Its text was shown; another answer would follow it.');
        }
    }

    public function testWithEveryModelRestingEachIsTriedTheSoonestBackFirst(): void
    {
        $fallback = $this->fallback([
            'opus' => [new ModelException('down', 503), new ModelException('down', 503)],
            'gpt' => [new ModelException('no key', 401)],
        ]);
        $ask = fn () => $fallback->stream(new ModelRequest('opus', []), static function (): void {});

        try {
            $ask();
            self::fail('With every model failing, the last failure is thrown.');
        } catch (ModelException $error) {
            self::assertSame(401, $error->status);
        }
        $this->now += 10;
        $result = $ask();

        self::assertSame(['opus', 'gpt', 'opus', 'gpt'], $this->asked, 'Both resting: opus is back first, then gpt.');
        self::assertSame('answer from gpt', $result->text);
    }

    /**
     * @param array<string, list<\Throwable|array{\Throwable, string}>> $failures Each model's failures, in turn.
     */
    private function fallback(array $failures): Fallback
    {
        $labels = ['opus' => 'anthropic/opus', 'gpt' => 'openai/gpt'];
        $models = [];
        foreach ($failures as $name => $list) {
            $models[] = ['name' => $name, 'label' => $labels[$name], 'model' => new ListedModel($list, $this->asked)];
        }
        return new Fallback(
            $models,
            function (string $message): void {
                $this->notices[] = $message;
            },
            300.0,
            fn (): float => $this->now,
        );
    }
}

final class ListedModel implements Model
{
    /**
     * @param list<\Throwable|array{\Throwable, string}> $failures
     * @param list<string> $asked
     */
    public function __construct(private array $failures, private array &$asked) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $this->asked[] = $request->model;
        $failure = array_shift($this->failures);
        if (is_array($failure)) {
            $emit(ModelEvent::text($failure[1]));
            throw $failure[0];
        }
        if ($failure !== null) {
            throw $failure;
        }
        $emit(ModelEvent::text("answer from {$request->model}"));
        return new ModelResult("answer from {$request->model}");
    }
}

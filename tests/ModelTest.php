<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Terminal\Loop;
use Crab\HttpClient\FakeClient;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    public function testOpenAiCompatibleStreamNormalizesTextAndUsage(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(
                200,
                "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\"!\"},\"finish_reason\":\"stop\"}],\"usage\":{\"total_tokens\":2}}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');
        $events = [];
        $result = Loop::run(static function ($cancellation) use ($model, &$events) {
            return $model->stream(
                new ModelRequest('test', [new Message('user', 'hello')]),
                static function (ModelEvent $event) use (&$events): void {
                    $events[] = $event;
                },
                $cancellation,
            );
        });

        self::assertSame('hi!', $result->text);
        self::assertSame(['total' => 2], $result->usage);
        self::assertSame(['text_delta', 'text_delta', 'usage', 'completed'], array_map(static fn (ModelEvent $event): string => $event->type, $events));
        self::assertCount(1, $http->sent('POST https://proxy.example/v1/chat/completions'));
    }

    public function testItAsksForUsageAndReadsOnlyAnswerTextFromResponsesEvents(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(
                200,
                "data: {\"choices\":[{\"delta\":{\"content\":\"a\"}}]}\n\n"
                . "data: {\"type\":\"response.function_call_arguments.delta\",\"delta\":\"{\\\"x\\\"\"}\n\n"
                . "data: {\"type\":\"response.output_text.delta\",\"delta\":\"b\"}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');

        $result = Loop::run(static fn ($cancellation) => $model->stream(new ModelRequest('test', [new Message('user', 'hi')]), static function (): void {}, $cancellation));

        self::assertSame('ab', $result->text, 'An argument delta was taken for answer text.');
        $sent = $http->sent('POST https://proxy.example/v1/chat/completions')[0];
        $body = json_decode((string) $sent->getBody(), true);
        self::assertSame(['include_usage' => true], $body['stream_options']);
    }

    public function testAProviderErrorCarriesItsMessageAndSaysWhenTheContextIsFull(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(
                400,
                '{"error":{"message":"This model\'s maximum context length is 8192 tokens.","code":"context_length_exceeded"}}',
                ['Content-Type' => 'application/json'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');

        try {
            Loop::run(static fn ($cancellation) => $model->stream(new ModelRequest('test', [new Message('user', 'hi')]), static function (): void {}, $cancellation));
            self::fail('An error answer was taken as a reply.');
        } catch (\Bia\Model\ModelException $error) {
            self::assertTrue($error->contextOverflow);
            self::assertStringContainsString('maximum context length', $error->getMessage());
        }
    }

    public function testReasoningIsStreamedApartFromTheAnswer(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(
                200,
                "data: {\"choices\":[{\"delta\":{\"reasoning_content\":\"think\"}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"content\":\"answer\"}}]}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');

        $result = Loop::run(static fn ($cancellation) => $model->stream(new ModelRequest('test', [new Message('user', 'hi')]), static function (): void {}, $cancellation));

        self::assertSame('answer', $result->text);
        self::assertSame('think', $result->reasoning);
    }

    public function testBrokenToolArgumentsBecomeAnError(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(
                200,
                "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"c1\",\"function\":{\"name\":\"write_file\",\"arguments\":\"{\\\"path\\\": \\\"a\"}}]}}]}\n\n"
                . "data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"index\":1,\"id\":\"c2\",\"function\":{\"name\":\"git_diff\",\"arguments\":\"\"}}]},\"finish_reason\":\"length\"}]}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');

        $result = Loop::run(static fn ($cancellation) => $model->stream(new ModelRequest('test', [new Message('user', 'hi')]), static function (): void {}, $cancellation));

        self::assertNotNull($result->toolCalls[0]->error);
        self::assertSame(['id' => 'c2', 'name' => 'git_diff', 'arguments' => []], $result->toolCalls[1]->toArray());
    }

    public function testModelListsAreReadInTheirCommonShapes(): void
    {
        self::assertSame(['gpt-5', 'claude'], \Bia\Model\ModelCatalog::parse(['data' => [['id' => 'gpt-5'], ['id' => 'claude']]]));
        self::assertSame(['gemini-2.5-pro'], \Bia\Model\ModelCatalog::parse(['models' => [['name' => 'models/gemini-2.5-pro']]]));
        $this->expectException(\RuntimeException::class);
        \Bia\Model\ModelCatalog::parse(['error' => 'nope']);
    }
}

<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Model\Lenient;
use Bia\Model\ModelException;
use Bia\Model\OpenAiCompatibleModel;
use Bia\Terminal\Loop;
use Crab\Engine\Async\Cancellation;
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
        self::assertArrayNotHasKey('reasoning_effort', $body, 'No effort is sent unless one was chosen.');
    }

    public function testResponsesWireSendsInputToResponsesAndNormalizesItsEvents(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/responses' => FakeClient::response(
                200,
                "data: {\"type\":\"response.output_text.delta\",\"delta\":\"hello\"}\n\n"
                . "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"usage\":{\"input_tokens\":4,\"output_tokens\":2,\"total_tokens\":6}}}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel(
            $http,
            'https://proxy.example/v1',
            'key',
            'test',
            wireApi: OpenAiCompatibleModel::RESPONSES,
        );
        $events = [];
        $result = Loop::run(static function ($cancellation) use ($model, &$events) { return $model->stream(
            new ModelRequest('test', [new Message('user', 'hi')], [], ['reasoning_effort' => 'high']),
            static function (ModelEvent $event) use (&$events): void { $events[] = $event; },
            $cancellation,
        ); });

        self::assertSame('hello', $result->text);
        self::assertSame(['input' => 4, 'output' => 2, 'total' => 6], $result->usage);
        self::assertSame(['text_delta', 'usage', 'completed'], array_map(static fn (ModelEvent $event): string => $event->type, $events));
        $request = $http->sent('POST https://proxy.example/v1/responses')[0];
        $body = json_decode((string) $request->getBody(), true);
        self::assertArrayHasKey('input', $body);
        self::assertArrayNotHasKey('messages', $body);
        self::assertFalse($body['store']);
        self::assertSame(['role' => 'user', 'content' => 'hi'], $body['input'][0]);
        self::assertSame(['effort' => 'high'], $body['reasoning']);
    }

    public function testResponsesWireConvertsToolsAndReadsFunctionCalls(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/responses' => FakeClient::response(
                200,
                "data: {\"type\":\"response.output_item.added\",\"item\":{\"type\":\"function_call\",\"id\":\"fc_1\",\"call_id\":\"call_1\",\"name\":\"write_file\"}}\n\n"
                . "data: {\"type\":\"response.function_call_arguments.delta\",\"item_id\":\"fc_1\",\"delta\":\"{\\\"path\\\":\\\"a.txt\\\"}\"}\n\n"
                . "data: {\"type\":\"response.function_call_arguments.done\",\"item_id\":\"fc_1\",\"arguments\":\"{\\\"path\\\":\\\"a.txt\\\"}\"}\n\n"
                . "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\"}}\n\n"
                . "data: [DONE]\n\n",
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test', wireApi: OpenAiCompatibleModel::RESPONSES);
        $tool = new \Bia\Contracts\ToolDefinition('write_file', 'Write a file', ['type' => 'object', 'properties' => []]);

        $result = Loop::run(static function ($cancellation) use ($model, $tool) { return $model->stream(
            new ModelRequest('test', [Message::user('write it')], [$tool]),
            static function (): void {},
            $cancellation,
        ); });

        self::assertSame([['id' => 'call_1', 'name' => 'write_file', 'arguments' => ['path' => 'a.txt']]], array_map(
            static fn (\Bia\Contracts\ToolCall $call): array => $call->toArray(),
            $result->toolCalls,
        ));
        $body = json_decode((string) $http->sent('POST https://proxy.example/v1/responses')[0]->getBody(), true);
        self::assertSame([
            ['type' => 'function', 'name' => 'write_file', 'description' => 'Write a file', 'strict' => false, 'parameters' => ['type' => 'object', 'properties' => []]],
        ], $body['tools']);
    }

    public function testResponsesInputKeepsImagesAndToolResultsInProviderShape(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bia-image-');
        self::assertIsString($path);
        file_put_contents($path, "\x89PNG\r\n\x1a\nbytes");
        try {
            $call = new \Bia\Contracts\ToolCall('call_1', 'look', ['x' => 1]);
            $input = OpenAiCompatibleModel::responsesInput([
                Message::user('look', [new \Bia\Contracts\Image($path, 'image/png')]),
                new Message('assistant', '', [$call]),
                Message::toolResult($call, 'done', true),
            ]);
            self::assertSame('input_image', $input[0]['content'][1]['type']);
            self::assertStringStartsWith('data:image/png;base64,', $input[0]['content'][1]['image_url']);
            self::assertSame(['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'look', 'arguments' => '{"x":1}'], $input[1]);
            self::assertSame(['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'done'], $input[2]);
        } finally {
            @unlink($path);
        }
    }

    public function testResponsesInputReplaysCompletedOutputItemsForReasoningToolTurns(): void
    {
        $call = new \Bia\Contracts\ToolCall('call_1', 'look', ['path' => 'a.txt']);
        $reasoning = [
            'type' => 'reasoning',
            'id' => 'rs_1',
            'summary' => [],
            'encrypted_content' => 'opaque-reasoning',
        ];
        $function = [
            'type' => 'function_call',
            'id' => 'fc_1',
            'call_id' => 'call_1',
            'name' => 'look',
            'arguments' => '{"path":"a.txt"}',
        ];

        $input = OpenAiCompatibleModel::responsesInput([
            Message::user('look'),
            new Message('assistant', '', [$call], meta: ['responses_output' => [$reasoning, $function]]),
            Message::toolResult($call, 'contents', true),
        ]);

        self::assertSame([$reasoning, $function], [$input[1], $input[2]]);
        self::assertSame(['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => 'contents'], $input[3]);
    }

    public function testResponsesJsonWrapperIsUnwrapped(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/responses' => FakeClient::response(
                200,
                json_encode(['response' => [
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => 'hello']],
                    ]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                ]], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json'],
            ),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test', wireApi: OpenAiCompatibleModel::RESPONSES);

        $result = Loop::run(static fn ($cancellation) => $model->stream(
            new ModelRequest('test', [Message::user('hi')]),
            static function (): void {},
            $cancellation,
        ));

        self::assertSame('hello', $result->text);
        self::assertSame(['input' => 1, 'output' => 1, 'total' => 2], $result->usage);
    }

    public function testTheReasoningEffortIsSentWithTheRequest(): void
    {
        $http = new FakeClient([
            'POST https://proxy.example/v1/chat/completions' => FakeClient::response(200, "data: [DONE]\n\n", ['Content-Type' => 'text/event-stream']),
        ]);
        $model = new OpenAiCompatibleModel($http, 'https://proxy.example/v1', 'key', 'test');

        Loop::run(static fn ($cancellation) => $model->stream(new ModelRequest('test', [new Message('user', 'hi')], [], ['reasoning_effort' => 'high']), static function (): void {}, $cancellation));

        $body = json_decode((string) $http->sent('POST https://proxy.example/v1/chat/completions')[0]->getBody(), true);
        self::assertSame('high', $body['reasoning_effort']);
    }

    public function testAnOptionTheProviderRefusesIsDroppedOnceAndForAll(): void
    {
        $refusing = new class implements Model {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->sent[] = $request->options;
                if (isset($request->options['reasoning_effort'])) {
                    throw new ModelException("Unrecognized request argument supplied: reasoning_effort", 400);
                }
                return new ModelResult('ok');
            }
        };
        $notes = [];
        $model = new Lenient($refusing, static function (string $note) use (&$notes): void {
            $notes[] = $note;
        });
        $request = new ModelRequest('gpt-4o', [new Message('user', 'hi')], [], ['reasoning_effort' => 'high', 'other' => 1]);

        self::assertSame('ok', $model->stream($request, static function (): void {})->text);
        self::assertSame('ok', $model->stream($request, static function (): void {})->text);
        self::assertSame([['reasoning_effort' => 'high', 'other' => 1], ['other' => 1], ['other' => 1]], $refusing->sent, 'Refused once, then never sent again.');
        self::assertSame(['gpt-4o takes no reasoning_effort; sent without it'], $notes);

        $this->expectException(ModelException::class);
        (new Lenient(new class implements Model {
            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                throw new ModelException('Invalid messages', 400);
            }
        }, static function (): void {}))->stream($request, static function (): void {});
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
        self::assertSame('reasoning_content', $result->reasoningField, 'The field it came in, to send it back in.');
    }

    public function testReasoningGoesBackInTheFieldItCameIn(): void
    {
        $thought = new Message('assistant', 'a', reasoning: 'why', meta: ['reasoning_field' => 'reasoning_content']);
        $kept = new Message('assistant', 'b', reasoning: 'old, from before the field was kept');
        $plain = new Message('assistant', 'c');

        $stored = OpenAiCompatibleModel::messages([$thought, $kept, $plain]);
        self::assertSame('why', $stored[0]['reasoning_content']);
        self::assertArrayNotHasKey('reasoning_content', $stored[1], 'Not knowing the field, it is not guessed.');
        self::assertArrayNotHasKey('reasoning_content', $stored[2]);

        $all = OpenAiCompatibleModel::messages([$thought, $kept, $plain], 'all');
        self::assertSame(['why', 'old, from before the field was kept', ''], array_column($all, 'reasoning_content'));

        self::assertArrayNotHasKey('reasoning_content', OpenAiCompatibleModel::messages([$thought], 'none')[0]);
    }

    public function testAProviderIsSentItsReasoningAsItAsksOnceAndForAll(): void
    {
        // As DeepSeek with tools: every earlier reply's reasoning must come back, or 400.
        $strict = new class implements Model {
            /** @var list<string> */
            public array $modes = [];

            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->modes[] = $request->reasoning;
                if ($request->reasoning !== 'all') {
                    throw new ModelException('The reasoning_content in the thinking mode must be passed back to the API.', 400);
                }
                return new ModelResult('ok');
            }
        };
        $notes = [];
        $model = new Lenient($strict, static function (string $note) use (&$notes): void {
            $notes[] = $note;
        });
        $request = new ModelRequest('deepseek-v4-pro', [Message::user('hi'), new Message('assistant', 'from another model'), Message::user('go on')]);
        $model->stream($request, static function (): void {});
        $model->stream($request, static function (): void {});
        self::assertSame(['stored', 'all', 'all'], $strict->modes, 'Mended once, then sent so from the start.');
        self::assertSame(['deepseek-v4-pro wants its reasoning back on every reply; sent'], $notes);

        // One that refuses the field: sent none from then on.
        $refusing = new class implements Model {
            /** @var list<string> */
            public array $modes = [];

            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->modes[] = $request->reasoning;
                if ($request->reasoning !== 'none') {
                    throw new ModelException("Unrecognized field 'messages[1].reasoning_content'", 400);
                }
                return new ModelResult('ok');
            }
        };
        $thought = new Message('assistant', 'a', reasoning: 'why', meta: ['reasoning_field' => 'reasoning_content']);
        (new Lenient($refusing, static function (): void {}))->stream(new ModelRequest('other', [Message::user('hi'), $thought]), static function (): void {});
        self::assertSame(['stored', 'none'], $refusing->modes);
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

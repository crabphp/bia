<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Agent;
use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelEvent;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\Renderer;
use Bia\Contracts\Tool;
use Bia\Contracts\ToolCall;
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Bia\Agent\Compactor;
use Bia\Contracts\Entry;
use Bia\Model\ModelException;
use Bia\Session\SqliteStore;
use Bia\Tool\Registry;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testTheUserSteersBetweenSteps(): void
    {
        [$store, $session] = $this->session();
        $model = new ScriptedModel([[self::call('call-1', 'echo', ['value' => 'wrong file'])]]);
        $asked = 0;
        $steer = static function () use (&$asked): array {
            return $asked++ === 0 ? ['no, the other file'] : [];
        };
        $agent = new Agent($model, 'fake', (new Registry())->add(new EchoTool()), $store, new FakeRenderer(), '/workspace');

        $agent->run($session, 'fix it', steer: $steer);

        self::assertSame(1, $asked, 'Asked once, after the one tool step, not before the first request.');
        $second = array_map(static fn (Message $m): string => $m->role . ':' . $m->text, $model->requests[1]->messages);
        self::assertSame(['user:fix it', 'assistant:', 'tool:echoed wrong file', 'user:no, the other file'], array_slice($second, -4), 'The message follows the tool results, before the next step.');
        self::assertContains('user:no, the other file', array_map(static fn (Message $m): string => $m->role . ':' . $m->text, self::messages($store, $session)), 'It is kept in the session.');
    }

    public function testAPassingFailureIsTriedAgain(): void
    {
        [$store, $session] = $this->session();
        $model = new FlakyModel([
            [new ModelException('busy', 503), ''],
            [new ModelException('slow down', 429, retryAfter: 0.0), ''],
        ]);
        $renderer = new FakeRenderer();
        $agent = new Agent($model, 'fake', new Registry(), $store, $renderer, '/workspace', retryDelays: [0.0, 0.0, 0.0]);

        self::assertSame('done', $agent->run($session, 'hi')->text);
        self::assertSame(3, $model->calls);
        self::assertSame(['503 · retry 1/3 in 0s', '429 · retry 2/3 in 0s'], $renderer->notices);
    }

    public function testARefusalIsNotTriedAgain(): void
    {
        [$store, $session] = $this->session();
        $model = new FlakyModel([[new ModelException('bad request', 400), '']]);
        $agent = new Agent($model, 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', retryDelays: [0.0]);

        try {
            $agent->run($session, 'hi');
            self::fail('A 400 is not tried again.');
        } catch (ModelException $error) {
            self::assertSame(400, $error->status);
        }
        self::assertSame(1, $model->calls);
        self::assertFalse((new ModelException('not implemented', 501))->transient(), 'A server that does not do what was asked will not later.');
        self::assertTrue((new ModelException('overloaded', 529))->transient());
    }

    public function testARoleLostByAProxyIsTriedAgain(): void
    {
        [$store, $session] = $this->session();
        $garbled = ModelException::fromAnswer(400, '{"error":{"message":"Invalid value: \'\'. Supported values are: \'assistant\', \'system\', \'developer\', and \'user\'."}}');
        $model = new FlakyModel([[$garbled, '']]);
        $agent = new Agent($model, 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', retryDelays: [0.0]);

        self::assertSame('done', $agent->run($session, 'hi')->text, 'The same request passes when sent again.');
        self::assertSame(2, $model->calls);
    }

    public function testTriesStopAfterTheLastWait(): void
    {
        [$store, $session] = $this->session();
        $model = new FlakyModel(array_fill(0, 4, [new ModelException('down', 502), '']));
        $agent = new Agent($model, 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', retryDelays: [0.0, 0.0]);

        $this->expectException(ModelException::class);
        try {
            $agent->run($session, 'hi');
        } finally {
            self::assertSame(3, $model->calls, 'One try, then one for each wait.');
        }
    }

    public function testAnAnswerCutOffPartwayIsNotTriedAgain(): void
    {
        [$store, $session] = $this->session();
        $model = new FlakyModel([[new ModelException('stream broke', 500), 'half an ans']]);
        $renderer = new FakeRenderer();
        $agent = new Agent($model, 'fake', new Registry(), $store, $renderer, '/workspace', retryDelays: [0.0]);

        $this->expectException(ModelException::class);
        try {
            $agent->run($session, 'hi');
        } finally {
            self::assertSame(1, $model->calls, 'Its text was already shown; trying again would show it twice.');
            self::assertSame('half an ans', $renderer->text);
        }
    }

    public function testToolTurnIsCheckpointedBeforeTheFinalTurn(): void
    {
        [$store, $session] = $this->session();
        $model = new ScriptedModel([[self::call('call-1', 'echo', ['value' => 'hello'])]]);
        $renderer = new FakeRenderer();
        $registry = (new Registry())->add(new EchoTool());
        $agent = new Agent($model, 'fake', $registry, $store, $renderer, '/workspace');

        $result = $agent->run($session, 'run the tool');
        $messages = self::messages($store, $session);

        self::assertSame('done', $result->text);
        self::assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn (Message $message): string => $message->role, $messages));
        self::assertSame('echoed hello', $messages[2]->text);
        self::assertSame(2, $model->calls);
        self::assertSame('done', $renderer->text);
        self::assertSame(['echo'], $renderer->tools, 'The renderer did not hear about the tool result.');
    }

    public function testReadOnlyCallsRunConcurrently(): void
    {
        [$store, $session] = $this->session();
        $calls = [
            self::call('a', 'sleep', ['seconds' => 0.2]),
            self::call('b', 'sleep', ['seconds' => 0.2]),
            self::call('c', 'sleep', ['seconds' => 0.2]),
        ];
        $agent = new Agent(new ScriptedModel([$calls]), 'fake', (new Registry())->add(new SleepTool()), $store, new FakeRenderer(), '/workspace');

        $started = microtime(true);
        $agent->run($session, 'sleep three times');

        self::assertLessThan(0.5, microtime(true) - $started, 'Read-only tools ran one after another.');
        $tools = array_values(array_filter(self::messages($store, $session), static fn (Message $m): bool => $m->role === 'tool'));
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Message $m): string => (string) $m->toolCallId, $tools), 'Results were stored out of call order.');
    }

    public function testUnreadableArgumentsAreAnsweredWithoutRunningTheTool(): void
    {
        [$store, $session] = $this->session();
        $broken = new ToolCall('x', 'echo', [], 'The arguments for echo were not a valid JSON object.');
        $tool = new EchoTool();
        $agent = new Agent(new ScriptedModel([[$broken]]), 'fake', (new Registry())->add($tool), $store, new FakeRenderer(), '/workspace');

        $agent->run($session, 'go');
        $messages = self::messages($store, $session);

        self::assertSame(0, $tool->runs);
        self::assertStringContainsString('not a valid JSON object', $messages[2]->text);
        self::assertSame('x', $messages[1]->toolCalls[0]->id);
    }

    public function testToolOutputIsMadeValidUtf8AndKeepsItsEnds(): void
    {
        [$store, $session] = $this->session();
        $agent = new Agent(
            new ScriptedModel([[self::call('big', 'big', [])]]),
            'fake',
            (new Registry())->add(new BigTool()),
            $store,
            new FakeRenderer(),
            '/workspace',
            maxOutputBytes: 2048,
            maxOutputLines: 40,
            outputs: $outputs = sys_get_temp_dir() . '/bia-outputs-' . bin2hex(random_bytes(4)),
        );

        $agent->run($session, 'go');
        $stored = self::messages($store, $session)[2]->text;

        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
        self::assertLessThan(2400, strlen($stored));
        self::assertStringStartsWith('START', $stored);
        self::assertStringEndsWith('END', $stored);
        self::assertMatchesRegularExpression('#saved at (\S+\.txt)#', $stored);
        preg_match('#saved at (\S+\.txt)#', $stored, $saved);
        self::assertStringStartsWith($outputs, $saved[1]);
        self::assertStringContainsString(str_repeat('x', 100), (string) file_get_contents($saved[1]), 'The full output was not saved.');
    }

    public function testAnInterruptedSessionIsRepairedWhenResumed(): void
    {
        [$store, $session] = $this->session();
        $store->append($session, Message::user('first'));
        $store->append($session, new Message('assistant', '', [new ToolCall('lost', 'echo')]));
        $model = new ScriptedModel([]);
        $agent = new Agent($model, 'fake', (new Registry())->add(new EchoTool()), $store, new FakeRenderer(), '/workspace');

        $agent->run($session, 'second');
        $sent = $model->requests[0]->messages;

        self::assertSame(['user', 'assistant', 'tool', 'user'], array_map(static fn (Message $m): string => $m->role, $sent));
        self::assertSame('lost', $sent[2]->toolCallId);
    }

    public function testACancelledTurnStillAnswersEveryCall(): void
    {
        [$store, $session] = $this->session();
        $cancellation = new Cancellation();
        $calls = [self::call('a', 'cancel', []), self::call('b', 'echo', ['value' => 'never'])];
        $registry = (new Registry())->add(new CancellingTool($cancellation))->add(new EchoTool());
        $agent = new Agent(new ScriptedModel([$calls]), 'fake', $registry, $store, new FakeRenderer(), '/workspace');

        try {
            $agent->run($session, 'go', cancellation: $cancellation);
            self::fail('A cancelled run returned.');
        } catch (\Crab\Engine\Async\CancelledException) {
        }
        $roles = array_map(static fn (Message $m): string => $m->role, self::messages($store, $session));

        self::assertSame(['user', 'assistant', 'tool', 'tool'], $roles);
    }

    public function testALongConversationIsCompactedAndKeepsItsRecentPart(): void
    {
        [$store, $session] = $this->session();
        for ($i = 1; $i <= 6; $i++) {
            $store->append($session, Message::user("question {$i} " . str_repeat('word ', 400)));
            $store->append($session, new Message('assistant', "answer {$i} " . str_repeat('word ', 400)));
        }
        $model = new ScriptedModel([]);
        $summarizer = new SummarizingModel();
        $renderer = new FakeRenderer();
        $agent = new Agent($model, 'fake', new Registry(), $store, $renderer, '/workspace', new Compactor($summarizer, 'fake', 4096, 1024, 1200));

        $agent->run($session, 'final question');
        $sent = $model->requests[0]->messages;

        self::assertSame(1, $summarizer->calls);
        self::assertStringContainsString('compacted', $sent[0]->text);
        self::assertStringContainsString('SUMMARY OF OLD WORK', $sent[0]->text);
        self::assertStringContainsString('question 1', $summarizer->transcript, 'The oldest part was not summarized.');
        self::assertSame('final question', $sent[count($sent) - 1]->text);
        self::assertLessThan(13, count($sent), 'Nothing was dropped from the context.');
        self::assertNotEmpty($renderer->notices);
        $types = array_map(static fn (Entry $entry): string => $entry->payload::class, $store->path($session));
        self::assertContains(\Bia\Contracts\Compaction::class, $types);
        self::assertCount(15, $types, 'Compaction rewrote the log instead of appending to it.');
    }

    public function testCompactionComesAtTheSetTokensAndAfterTheCacheIsGone(): void
    {
        $compactor = new Compactor(new SummarizingModel(), 'fake', 1_000_000, 16_384, 20_000, atTokens: 400_000, idleSeconds: 3600, idleTokens: 64_000);
        self::assertFalse($compactor->needed(399_000));
        self::assertTrue($compactor->needed(401_000), 'A large window waits for atTokens, not the window.');
        self::assertTrue((new Compactor(new SummarizingModel(), 'fake', 200_000, 16_384, 20_000, atTokens: 400_000))->needed(190_000), 'A small window still comes first.');
        self::assertFalse($compactor->needed(100_000, 3599.0), 'The cache is still warm.');
        self::assertFalse($compactor->needed(60_000, 7200.0), 'Too small to be worth it.');
        self::assertTrue($compactor->needed(100_000, 3600.0));

        // A conversation of about 6k tokens that rested two hours: compacted before the prompt.
        [$store, $session] = $this->session();
        for ($i = 1; $i <= 6; $i++) {
            $store->append($session, Message::user("question {$i} " . str_repeat('word ', 400)));
            $store->append($session, new Message('assistant', "answer {$i} " . str_repeat('word ', 400)));
        }
        $model = new ScriptedModel([]);
        $summarizer = new SummarizingModel();
        $later = static fn (): float => microtime(true) + 7200;
        $rested = new Compactor($summarizer, 'fake', 100_000, 1024, 1200, idleSeconds: 3600, idleTokens: 2000, clock: $later);
        (new Agent($model, 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', $rested))->run($session, 'back again');
        self::assertSame(1, $summarizer->calls);
        self::assertStringContainsString('SUMMARY OF OLD WORK', $model->requests[0]->messages[0]->text);

        // The same, answered a moment ago: left as it is.
        $summarizer = new SummarizingModel();
        $fresh = new Compactor($summarizer, 'fake', 100_000, 1024, 1200, idleSeconds: 3600, idleTokens: 2000);
        (new Agent(new ScriptedModel([]), 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', $fresh))->run($session, 'and now');
        self::assertSame(0, $summarizer->calls);
    }

    public function testCompactionNeverPartsAToolCallFromItsResult(): void
    {
        [$store, $session] = $this->session();
        $store->append($session, Message::user('start ' . str_repeat('word ', 300)));
        for ($i = 1; $i <= 8; $i++) {
            $call = new ToolCall("c{$i}", 'echo', ['value' => str_repeat('v', 400)]);
            $store->append($session, new Message('assistant', '', [$call]));
            $store->append($session, Message::toolResult($call, str_repeat('result ', 200), true));
        }
        $model = new ScriptedModel([]);
        $agent = new Agent($model, 'fake', (new Registry())->add(new EchoTool()), $store, new FakeRenderer(), '/workspace', new Compactor(new SummarizingModel(), 'fake', 4096, 1024, 1000));

        $agent->run($session, 'go on');
        $sent = $model->requests[0]->messages;

        self::assertNotSame('tool', $sent[1]->role, 'The kept part starts with a result whose call was summarized away.');
        $open = [];
        foreach ($sent as $message) {
            foreach ($message->toolCalls as $call) {
                $open[$call->id] = true;
            }
            if ($message->role === 'tool') {
                self::assertArrayHasKey((string) $message->toolCallId, $open);
                unset($open[(string) $message->toolCallId]);
            }
        }
        self::assertSame([], $open);
    }

    /**
     * @return array{SqliteStore, string}
     */
    private function session(): array
    {
        $store = new SqliteStore(sys_get_temp_dir() . '/bia-agent-' . bin2hex(random_bytes(4)) . '.sqlite');
        return [$store, $store->create('/workspace', 'fake')];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function call(string $id, string $name, array $arguments): ToolCall
    {
        return new ToolCall($id, $name, $arguments);
    }

    /**
     * @return list<Message>
     */
    private static function messages(SqliteStore $store, string $session): array
    {
        return array_values(array_filter(array_map(static fn (Entry $entry): ?Message => $entry->message(), $store->path($session))));
    }
}

/**
 * Answers with each scripted batch of tool calls in turn, then with "done".
 */
final class ScriptedModel implements Model
{
    public int $calls = 0;
    /**
     * @var list<ModelRequest>
     */
    public array $requests = [];

    /**
     * @param list<list<ToolCall>> $turns
     */
    public function __construct(private array $turns) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $this->calls++;
        $this->requests[] = $request;
        $calls = array_shift($this->turns);
        if ($calls !== null) {
            foreach ($calls as $call) {
                $emit(ModelEvent::toolCall($call->toArray()));
            }
            return new ModelResult('', 'tool_calls', toolCalls: $calls);
        }
        $emit(ModelEvent::text('done'));
        return new ModelResult('done');
    }
}

final class SummarizingModel implements Model
{
    public int $calls = 0;
    public string $transcript = '';

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $this->calls++;
        $messages = $request->messages;
        $this->transcript = end($messages)->text;
        return new ModelResult('SUMMARY OF OLD WORK');
    }
}

final class EchoTool implements Tool
{
    public int $runs = 0;

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('echo', 'Echo a value.', [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'string']],
            'required' => ['value'],
        ]);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $this->runs++;
        return new ToolResult('echoed ' . ($arguments['value'] ?? ''));
    }
}

final class SleepTool implements Tool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('sleep', 'Sleep.', ['type' => 'object']);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        Async::sleep(EventLoop::current(), (float) $arguments['seconds'], $cancellation);
        return new ToolResult('slept');
    }
}

final class BigTool implements Tool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('big', 'Big output.', ['type' => 'object']);
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        return new ToolResult("START\n" . str_repeat("é line\n", 500) . "\xff\xfe\n" . str_repeat('x', 3000) . "\nEND");
    }
}

final class CancellingTool implements Tool
{
    public function __construct(private Cancellation $cancellation) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('cancel', 'Cancels the turn.', ['type' => 'object'], 'shell');
    }

    public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
    {
        $this->cancellation->cancel();
        $this->cancellation->throwIfCancelled();
        return new ToolResult('unreachable');
    }
}

/**
 * Fails with the given errors first, each after the text given with it, then answers.
 */
final class FlakyModel implements Model
{
    public int $calls = 0;

    /**
     * @param list<array{\Throwable, string}> $failures
     */
    public function __construct(private array $failures) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $this->calls++;
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            if ($failure[1] !== '') {
                $emit(ModelEvent::text($failure[1]));
            }
            throw $failure[0];
        }
        $emit(ModelEvent::text('done'));
        return new ModelResult('done');
    }
}

final class FakeRenderer implements Renderer
{
    public string $text = '';
    /**
     * @var list<string>
     */
    public array $tools = [];

    public function event(ModelEvent $event): void
    {
        $this->text .= $event->text;
    }

    /**
     * @var list<string>
     */
    public array $notices = [];

    public function tool(ToolCall $call, ToolResult $result): void
    {
        $this->tools[] = $call->name;
    }

    public function notice(string $message): void
    {
        $this->notices[] = $message;
    }

    public function finish(ModelResult $result): void {}

    public function running(ToolCall $call, string $output): void {}
}

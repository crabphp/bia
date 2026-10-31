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
use Bia\Contracts\ToolContext;
use Bia\Contracts\ToolDefinition;
use Bia\Contracts\ToolResult;
use Bia\Session\SqliteStore;
use Bia\Tool\Registry;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testToolTurnIsCheckpointedBeforeTheFinalTurn(): void
    {
        [$store, $session] = $this->session();
        $model = new ScriptedModel([[self::call('call-1', 'echo', ['value' => 'hello'])]]);
        $renderer = new FakeRenderer();
        $registry = (new Registry())->add(new EchoTool());
        $agent = new Agent($model, 'fake', $registry, $store, $renderer, '/workspace');

        $result = $agent->run($session, 'run the tool');
        $messages = $store->messages($session);

        self::assertSame('done', $result->text);
        self::assertSame(['user', 'assistant', 'tool', 'assistant'], array_map(static fn (Message $message): string => $message->role, $messages));
        self::assertSame('echoed hello', $messages[2]->content);
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
        $tools = array_values(array_filter($store->messages($session), static fn (Message $m): bool => $m->role === 'tool'));
        self::assertSame(['a', 'b', 'c'], array_map(static fn (Message $m): string => $m->metadata['tool_call_id'], $tools), 'Results were stored out of call order.');
    }

    public function testUnreadableArgumentsAreAnsweredWithoutRunningTheTool(): void
    {
        [$store, $session] = $this->session();
        $broken = ['id' => 'x', 'name' => 'echo', 'arguments' => [], 'error' => 'The arguments for echo were not a valid JSON object.'];
        $tool = new EchoTool();
        $agent = new Agent(new ScriptedModel([[$broken]]), 'fake', (new Registry())->add($tool), $store, new FakeRenderer(), '/workspace');

        $agent->run($session, 'go');
        $messages = $store->messages($session);

        self::assertSame(0, $tool->runs);
        self::assertStringContainsString('not a valid JSON object', $messages[2]->content);
        self::assertSame('{}', $messages[1]->metadata['tool_calls'][0]['function']['arguments']);
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
            maxStoredToolBytes: 1024,
        );

        $agent->run($session, 'go');
        $stored = $store->messages($session)[2]->content;

        self::assertTrue(mb_check_encoding($stored, 'UTF-8'));
        self::assertLessThan(1100, strlen($stored));
        self::assertStringStartsWith('START', $stored);
        self::assertStringEndsWith('END', $stored);
        self::assertStringContainsString('bytes omitted', $stored);
    }

    public function testAnInterruptedSessionIsRepairedWhenResumed(): void
    {
        [$store, $session] = $this->session();
        $store->append($session, new Message('user', 'first'));
        $store->append($session, new Message('assistant', '', [
            'tool_calls' => [['id' => 'lost', 'type' => 'function', 'function' => ['name' => 'echo', 'arguments' => '{}']]],
        ]));
        $model = new ScriptedModel([]);
        $agent = new Agent($model, 'fake', (new Registry())->add(new EchoTool()), $store, new FakeRenderer(), '/workspace');

        $agent->run($session, 'second');
        $sent = $model->requests[0]->jsonMessages();

        self::assertSame(['user', 'assistant', 'tool', 'user'], array_column($sent, 'role'));
        self::assertSame('lost', $sent[2]['tool_call_id']);
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
        $roles = array_map(static fn (Message $m): string => $m->role, $store->messages($session));

        self::assertSame(['user', 'assistant', 'tool', 'tool'], $roles);
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
     * @return array{id: string, name: string, arguments: array<string, mixed>}
     */
    private static function call(string $id, string $name, array $arguments): array
    {
        return ['id' => $id, 'name' => $name, 'arguments' => $arguments];
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
     * @param list<list<array<string, mixed>>> $turns
     */
    public function __construct(private array $turns) {}

    public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
    {
        $this->calls++;
        $this->requests[] = $request;
        $calls = array_shift($this->turns);
        if ($calls !== null) {
            foreach ($calls as $call) {
                $emit(ModelEvent::toolCall($call));
            }
            return new ModelResult('', 'tool_calls', toolCalls: $calls);
        }
        $emit(ModelEvent::text('done'));
        return new ModelResult('done');
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
        return new ToolResult('START' . str_repeat('é', 5000) . "\xff\xfe" . str_repeat('x', 3000) . 'END');
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

    public function tool(array $call, ToolResult $result): void
    {
        $this->tools[] = $call['name'];
    }

    public function finish(ModelResult $result): void {}
}

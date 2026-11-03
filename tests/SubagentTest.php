<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Agent;
use Bia\Agent\AgentTool;
use Bia\Agent\Subagents;
use Bia\Contracts\Message;
use Bia\Contracts\ToolCall;
use Bia\Session\MemoryStore;
use Bia\Tool\Registry;
use Crab\Engine\Async\Cancellation;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AgentTest.php'; // its fake models and tools

final class SubagentTest extends TestCase
{
    public function testSubagentsWorkTogetherAndOnlyTheirAnswersComeBack(): void
    {
        $made = [];
        $subagents = $this->subagents($made, static fn (): array => [[new ToolCall('s1', 'sleep', ['seconds' => 0.2])]]);
        $store = new MemoryStore();
        $session = $store->create('/workspace', 'fake');
        $model = new ScriptedModel([[
            new ToolCall('a', 'agent', ['name' => 'Tests Dir', 'task' => 'find the tests']),
            new ToolCall('b', 'agent', ['name' => 'docs', 'task' => 'find the docs']),
        ]]);
        $main = new Agent($model, 'fake', (new Registry())->add(new AgentTool($subagents)), $store, new FakeRenderer(), '/workspace');

        $started = microtime(true);
        $main->run($session, 'look around');

        self::assertLessThan(0.35, microtime(true) - $started, 'They worked at the same time.');
        $results = array_values(array_filter(array_map(static fn ($e) => $e->message(), $store->path($session)), static fn (?Message $m): bool => $m?->role === 'tool'));
        self::assertSame(['done', 'done'], array_map(static fn (Message $m): string => $m->text, $results), 'Only the answer, none of the work.');
        self::assertSame(['tests-dir', 'docs'], array_column($subagents->all(), 'name'), 'Named short and plain.');
        self::assertSame(['done', 'done'], array_column($subagents->all(), 'state'));
        self::assertSame([1, 1], array_column($subagents->all(), 'calls'));
    }

    public function testAFifthWaitsForOneOfTheFourToFinish(): void
    {
        $made = [];
        $subagents = $this->subagents($made, static fn (): array => [[new ToolCall('s', 'sleep', ['seconds' => 0.2])]]);
        $store = new MemoryStore();
        $session = $store->create('/workspace', 'fake');
        $calls = array_map(static fn (int $i): ToolCall => new ToolCall("c{$i}", 'agent', ['name' => "a{$i}", 'task' => 'wait']), range(1, 5));
        $main = new Agent(new ScriptedModel([$calls]), 'fake', (new Registry())->add(new AgentTool($subagents)), $store, new FakeRenderer(), '/workspace');

        $started = microtime(true);
        $main->run($session, 'five at once');
        $took = microtime(true) - $started;

        self::assertGreaterThan(0.35, $took, 'The fifth waited.');
        self::assertLessThan(0.6, $took, 'Four worked together.');
        self::assertSame(array_fill(0, 5, 'done'), array_column($subagents->all(), 'state'));
    }

    public function testTheSameNameFollowsUpAndFinishedOnesAreForgotten(): void
    {
        $made = [];
        $subagents = $this->subagents($made, static fn (): array => []);
        self::assertTrue($subagents->ask('tests', 'find the tests')->ok);
        self::assertTrue($subagents->ask('tests', 'and the fixtures?')->ok);

        self::assertCount(1, $made, 'Continued, not made again.');
        $asked = array_map(static fn ($e) => $e->message()?->text, $made[0]['store']->path($made[0]['session']));
        self::assertContains('find the tests', $asked);
        self::assertContains('and the fixtures?', $asked, 'The follow-up joins its own conversation.');

        $subagents->ask('docs', 'find the docs');
        $subagents->close('tests');
        self::assertSame(['docs'], array_column($subagents->all(), 'name'), 'Closed, one finished is gone.');

        $subagents->forget();
        self::assertSame([], $subagents->all());
    }

    public function testOneStoppedTellsTheMainAgentAndAStoppedTurnStopsThemAll(): void
    {
        $made = [];
        $subagents = $this->subagents($made, static fn (): array => [[new ToolCall('s1', 'sleep', ['seconds' => 5])]]);
        $loop = \Crab\Engine\EventLoop::current();
        $loop->delay(0.05, static fn () => $subagents->stop('slow'));
        $result = $subagents->ask('slow', 'take long');
        self::assertFalse($result->ok);
        self::assertStringContainsString('stopped', $result->text);
        self::assertSame('stopped', $subagents->all()[0]['state']);

        $turn = new Cancellation();
        $loop->delay(0.05, static fn () => $turn->cancel());
        $this->expectException(\Crab\Engine\Async\CancelledException::class);
        $subagents->ask('other', 'take long', $turn);
    }

    /**
     * @param list<array{store: MemoryStore, session: string}> $made
     * @param \Closure(): list<list<ToolCall>> $turns Each subagent's scripted steps.
     */
    private function subagents(array &$made, \Closure $turns): Subagents
    {
        return new Subagents(static function (string $name, \Closure $doing, \Closure $called) use (&$made, $turns): array {
            $store = new MemoryStore();
            $session = $store->create('/workspace', 'fake');
            $made[] = ['store' => $store, 'session' => $session];
            $renderer = new class ($called) implements \Bia\Contracts\Renderer {
                public function __construct(private \Closure $called) {}

                public function event(\Bia\Contracts\ModelEvent $event): void {}

                public function running(ToolCall $call, string $output): void {}

                public function tool(ToolCall $call, \Bia\Contracts\ToolResult $result): void
                {
                    ($this->called)();
                }

                public function notice(string $message): void {}

                public function finish(\Bia\Contracts\ModelResult $result): void {}
            };
            return [new Agent(new ScriptedModel($turns()), 'fake', (new Registry())->add(new SleepTool()), $store, $renderer, '/workspace'), $session, null];
        }, static function (): void {});
    }
}

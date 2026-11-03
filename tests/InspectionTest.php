<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Agent;
use Bia\Agent\Compactor;
use Bia\Agent\Inspection;
use Bia\Contracts\Message;
use Bia\Contracts\ToolCall;
use Bia\Model\ModelException;
use Bia\Session\SqliteStore;
use Bia\Tool\Registry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AgentTest.php'; // its fake models and tools

final class InspectionTest extends TestCase
{
    public function testNothingIsReportedBeforeTheFirstRequest(): void
    {
        self::assertNull((new Inspection())->report());
    }

    public function testTheLastRequestKeepsItsRetriesAndItsTurn(): void
    {
        [$store, $session] = $this->session();
        $inspection = new Inspection();
        $model = new ScriptedModel([[new ToolCall('call-1', 'echo', ['value' => 'x'])]]);
        $agent = new Agent($model, 'fake', (new Registry())->add(new EchoTool()), $store, new FakeRenderer(), '/workspace', options: ['reasoning_effort' => 'high'], inspection: $inspection);
        $agent->run($session, 'hi', 'You are Bia.');

        $rows = self::rows($inspection);
        self::assertSame('fake · effort high', $rows['Model']);
        self::assertMatchesRegularExpression('/^≈\d+ tokens · system \d+ · tools \d+ \(1\) · messages \d+ \(2\) · tool results \d+ \(1\)$/', $rows['Sent']);
        self::assertSame('stop', $rows['Reply']);
        self::assertSame('2 requests · in 0 · out 0', $rows['Turn']);

        $flaky = new Agent(new FlakyModel([[new ModelException('busy', 503), '']]), 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', retryDelays: [0.0], inspection: $inspection);
        $flaky->run($session, 'again');
        $rows = self::rows($inspection);
        self::assertSame('503 · retry 1/1 in 0s', $rows['Event']);
        self::assertArrayNotHasKey('Turn', $rows, 'A new turn counts afresh.');
    }

    public function testAFailureAndACompactionAreShown(): void
    {
        [$store, $session] = $this->session();
        for ($i = 1; $i <= 6; $i++) {
            $store->append($session, Message::user("question {$i} " . str_repeat('word ', 400)));
            $store->append($session, new Message('assistant', "answer {$i} " . str_repeat('word ', 400)));
        }
        $inspection = new Inspection();
        $agent = new Agent(new FlakyModel([[new ModelException('bad key', 401), '']]), 'fake', new Registry(), $store, new FakeRenderer(), '/workspace', new Compactor(new SummarizingModel(), 'fake', 4096, 1024, 1200), inspection: $inspection);
        try {
            $agent->run($session, 'final question');
            self::fail('A refused key is not tried again.');
        } catch (ModelException) {
        }

        $rows = self::rows($inspection);
        self::assertSame('bad key', $rows['Failed']);
        self::assertMatchesRegularExpression('/^\S+ → \S+ tokens · \d+ messages summarized$/', $rows['Compacted']);
    }

    /**
     * @return array<string, string>
     */
    private static function rows(Inspection $inspection): array
    {
        $rows = [];
        foreach ($inspection->report()['rows'] ?? [] as [$name, $text]) {
            $rows[$name] = $text;
        }
        return $rows;
    }

    /**
     * @return array{SqliteStore, string}
     */
    private function session(): array
    {
        $store = new SqliteStore(sys_get_temp_dir() . '/bia-inspect-' . bin2hex(random_bytes(4)) . '.sqlite');
        return [$store, $store->create('/workspace', 'fake')];
    }
}

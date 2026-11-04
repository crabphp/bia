<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Recap;
use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Session\SqliteStore;
use Bia\Terminal\RecapSchedule;
use Bia\Tui\Keys;
use Crab\Engine\Async\Cancellation;
use PHPUnit\Framework\TestCase;

final class RecapTest extends TestCase
{
    public function testItIsWrittenFromWordsAndWhatToolsDidNeverTheirOutput(): void
    {
        $entries = self::entries(
            Message::user('Fix the failing login test'),
            new Message('assistant', 'Looking.', [
                new ToolCall('a', 'shell', ['command' => "composer   test\n--filter Login"]),
                new ToolCall('b', 'edit', ['path' => 'src/Auth.php']),
                new ToolCall('c', 'read_file', ['path' => 'src/Auth.php']),
            ]),
            Message::toolResult(new ToolCall('a', 'shell'), 'SECRET OUTPUT of the test run', false),
            Message::toolResult(new ToolCall('b', 'edit'), 'edited', true),
            Message::toolResult(new ToolCall('c', 'read_file'), 'file body', true),
            new Message('assistant', 'Fixed; the test passes now.'),
            Message::user('Now push it'),
        );
        $transcript = Recap::transcript($entries);
        self::assertSame(<<<'TEXT'
            User: Fix the failing login test
            Tools: ran composer test --filter Login (failed) · edited src/Auth.php · read or listed 1× · 1 failed
            Agent: Looking.

            Fixed; the test passes now.

            User, waiting: Now push it
            TEXT, $transcript);
        self::assertStringNotContainsString('SECRET', $transcript, 'Tool output is never sent.');
    }

    public function testOnlyTheLastExchangesAreSentAndEachIsCutToFit(): void
    {
        $messages = [new Compaction('They set up the project.', 1, 1000, 100)];
        foreach (range(1, 12) as $n) {
            $messages[] = Message::user("question {$n} " . str_repeat('x', 3000));
            $messages[] = new Message('assistant', "answer {$n}");
        }
        $transcript = Recap::transcript(self::entries(...$messages), 8000);
        self::assertStringStartsWith('Earlier, summarized: They set up the project.', $transcript, 'What was summarized comes first.');
        self::assertStringNotContainsString('question 4 ', $transcript, 'At most the last eight exchanges.');
        self::assertStringContainsString('answer 12', $transcript);
        self::assertLessThanOrEqual(8000, strlen($transcript));
        self::assertSame('', Recap::transcript([]));
    }

    public function testTheAnswerIsReadAsARecapAndWhatIsNext(): void
    {
        self::assertSame(['summary' => 'Reconnect is built and pushed.', 'next' => 'Pick the idle time.'], Recap::parse("Recap: Reconnect is built\nand pushed.\n\n**Next:** Pick the idle time."));
        self::assertSame(['summary' => 'Only proposed so far.', 'next' => null], Recap::parse('Only proposed so far.'));
        self::assertNull(Recap::parse("  \n"));
        self::assertSame(700, mb_strlen(Recap::parse(str_repeat('word ', 300))['summary'] ?? ''), 'Kept short whatever the model writes.');
    }

    public function testItIsOneSmallRequestWithNoTools(): void
    {
        $model = new class implements Model {
            public ?ModelRequest $request = null;

            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->request = $request;
                return new ModelResult("The login test is fixed, not pushed.\nNext: push it.");
            }
        };
        $recap = new Recap($model, 'm', ['reasoning_effort' => 'low']);
        self::assertSame(['summary' => 'The login test is fixed, not pushed.', 'next' => 'push it.'], $recap->write(self::entries(Message::user('fix it'), new Message('assistant', 'Fixed.'))));
        self::assertSame([], $model->request?->tools);
        self::assertSame(['reasoning_effort' => 'low'], $model->request?->options);
        self::assertStringContainsString("User: fix it\nAgent: Fixed.", (string) ($model->request?->messages[1]->text ?? ""));
        self::assertNull($recap->write([]), 'Nothing to recap: no request.');
    }

    public function testItIsDueOnlyAfterTimeAwayAndEnoughTurns(): void
    {
        $away = new RecapSchedule(0.0);
        $away->seed(2, false);
        self::assertNull($away->due(1800), 'Not before three turns.');
        $away->turnDone();
        self::assertSame(1800.0, $away->due(1800), 'Until the terminal tells its focus, away is no key pressed.');
        $away->active(100.0);
        self::assertSame(1900.0, $away->due(1800));

        $away->focus(true, 200.0);
        self::assertNull($away->due(1800), 'Focused, the user is here.');
        $away->focus(false, 500.0);
        self::assertSame(2300.0, $away->due(1800), 'Away from the moment focus was lost.');
        $away->focus(false, 900.0);
        self::assertSame(2300.0, $away->due(1800), 'Still away since then.');
        $away->active(1000.0);
        self::assertSame(2800.0, $away->due(1800), 'Or since a turn ended, whichever was later.');

        $away->recapped(3);
        self::assertTrue($away->current());
        $away->turnDone();
        self::assertNull($away->due(1800), 'Not again before two more turns.');
        self::assertFalse($away->current());
        $away->turnDone();
        self::assertNotNull($away->due(1800));
    }

    public function testASessionKeepsItsRecapWhileNothingHappened(): void
    {
        $store = new SqliteStore(sys_get_temp_dir() . '/bia-recap-' . bin2hex(random_bytes(4)) . '.sqlite');
        $id = $store->create('/tmp', 'm');
        $store->append($id, Message::user('hi'));
        self::assertNull($store->recap($id));
        $store->saveRecap($id, ['summary' => 'Said hi.', 'next' => null]);
        self::assertSame(['summary' => 'Said hi.', 'next' => null, 'head' => $store->head($id)], $store->recap($id));
        $store->append($id, new Message('assistant', 'hello'));
        self::assertNotSame($store->head($id), $store->recap($id)['head'] ?? null, 'Out of date once the session moved on.');
    }

    public function testTheTerminalsFocusIsReadAsKeys(): void
    {
        $buffer = "\e[O\e[Ia";
        self::assertSame('focus-out', Keys::take($buffer)?->name);
        self::assertSame('focus-in', Keys::take($buffer)?->name);
        self::assertSame('a', Keys::take($buffer)?->text);
    }

    /**
     * @return list<Entry>
     */
    private static function entries(Message|Compaction ...$payloads): array
    {
        $entries = [];
        foreach ($payloads as $index => $payload) {
            $entries[] = new Entry($index + 1, $index === 0 ? null : $index, $payload);
        }
        return $entries;
    }
}

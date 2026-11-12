<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Agent;
use Bia\Agent\Title;
use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\Model;
use Bia\Contracts\ModelRequest;
use Bia\Contracts\ModelResult;
use Bia\Contracts\ToolCall;
use Bia\Session\Naming;
use Bia\Session\SqliteStore;
use Bia\Tool\Registry;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    protected function setUp(): void
    {
        EventLoop::install(new StreamSelectEventLoop());
    }

    public function testItUsesASmallToolFreeRequestOutsideTheConversation(): void
    {
        $model = $this->model('Automatic session naming');
        $title = new Title($model, 'm', ['reasoning_effort' => 'low']);
        $call = new ToolCall('c', 'read_file', ['path' => 'src/Session.php']);
        $entries = self::entries(
            Message::user('hi'), new Message('assistant', 'Hello!'),
            Message::user('Name sessions with the agent, not the first chat message'),
            new Message('assistant', 'Checking.', [$call]),
            Message::toolResult($call, 'PRIVATE FILE BODY', true),
            new Message('assistant', 'We can generate a name in the background.'),
        );
        self::assertSame('Automatic session naming', $title->write($entries));
        $request = $model->requests[0];
        self::assertSame('m', $request->model);
        self::assertSame([], $request->tools);
        self::assertSame(['reasoning_effort' => 'low'], $request->options);
        self::assertStringContainsString('return exactly NONE', $request->messages[0]->text);
        self::assertStringContainsString('User: hi', $request->messages[1]->text);
        self::assertStringContainsString('Name sessions with the agent', $request->messages[1]->text);
        self::assertStringNotContainsString('PRIVATE FILE BODY', $request->messages[1]->text);
        self::assertNull($title->write([]));
        self::assertCount(1, $model->requests);
    }

    public function testTheModelCanDeferNamingAndReceivesBoundedInput(): void
    {
        $model = $this->model('NONE');
        $title = new Title($model, 'm');
        self::assertNull($title->write(self::entries(Message::user('hi'))));
        $title->write(self::entries(Message::user(str_repeat('long prompt ', 10000))));
        self::assertLessThanOrEqual(8040, strlen($model->requests[1]->messages[1]->text));
    }

    public function testNamesAreShortPrintableAndNotExplanations(): void
    {
        foreach (['', '  NONE  ', 'none', "Name\nExplanation", "\e[31mRed title", "Hidden\u{202e}title", str_repeat('x', 81)] as $text) {
            self::assertNull(Title::parse($text));
        }
        self::assertSame('Fix login validation', Title::parse(' "Fix login validation" '));
        self::assertSame('日本語のセッション名', Title::parse('日本語のセッション名'));
        self::assertSame(60, mb_strlen((string) Title::parse(str_repeat('é', 70))));
    }

    public function testNamingIsDeferredAndRetriesAfterANewTurnButStopsOnceNamed(): void
    {
        [$store, $id] = $this->session();
        $naming = new Naming($store);
        $calls = 0;
        $write = static function () use (&$calls): ?string {
            return ++$calls === 1 ? null : 'Automatic session naming';
        };
        $naming->start($id, $write);
        $naming->start($id, $write); // a no-op compaction must not cancel the pending name
        self::assertSame(0, $calls, 'Starting a name never waits for its model.');
        EventLoop::current()->run();
        self::assertSame(1, $calls);
        self::assertNull($store->title($id), 'A greeting can stay unnamed.');
        $naming->start($id, $write);
        EventLoop::current()->run();
        self::assertSame(1, $calls, 'No repeat requests about the same completed turn.');

        $store->append($id, Message::user('Name sessions automatically'));
        $store->append($id, new Message('assistant', 'Yes, in the background.'));
        $naming->start($id, $write);
        EventLoop::current()->run();
        self::assertSame('Automatic session naming', $store->title($id));
        $store->append($id, Message::user('Another topic'));
        $naming->start($id, $write);
        EventLoop::current()->run();
        self::assertSame(2, $calls, 'Once named, keep it stable.');
    }

    public function testNewWorkCancelsNamingAndADelayedResultCannotOverwriteANewerHead(): void
    {
        [$store, $id] = $this->session();
        $naming = new Naming($store);
        $cancelled = false;
        $loop = EventLoop::current();
        $naming->start($id, static function (string $_, Cancellation $c) use ($loop, $naming, &$cancelled): string {
            $loop->delay(0.001, static fn () => $naming->cancel());
            Async::sleep($loop, 0.005); // even a writer that ignores cancellation cannot save a result
            $cancelled = $c->isCancelled();
            return 'Too late to save';
        });
        $loop->run();
        self::assertTrue($cancelled);
        self::assertNull($store->title($id));

        $store->append($id, new Message('assistant', 'A later turn.'));
        $naming->start($id, static function () use ($store, $id): string {
            $store->append($id, Message::user('A new message while naming'));
            return 'Stale result';
        });
        $loop->run();
        self::assertNull($store->title($id), 'An outdated head is rejected atomically.');
    }

    public function testCancellationAndFailuresAreSilentAndDoNotPreventTheNextAttempt(): void
    {
        [$store, $id] = $this->session();
        $naming = new Naming($store);
        $calls = 0;
        $write = static function () use (&$calls): string {
            $calls++;
            throw new \RuntimeException('Provider unavailable');
        };
        $naming->start($id, $write);
        $naming->cancel(); // exit, switch, reload, or new foreground work, before it even starts
        EventLoop::current()->run();
        self::assertSame(0, $calls);
        $store->append($id, new Message('assistant', 'Another completed turn.'));
        $naming->start($id, $write);
        EventLoop::current()->run();
        self::assertSame(1, $calls);
        self::assertNull($store->title($id));
        $store->append($id, new Message('assistant', 'And another.'));
        $naming->start($id, static fn (): string => 'Recovered session name');
        EventLoop::current()->run();
        self::assertSame('Recovered session name', $store->title($id));
    }

    public function testTheBackgroundRequestHasADeadline(): void
    {
        [$store, $id] = $this->session();
        $naming = new Naming($store, timeout: 0.005);
        $started = microtime(true);
        $naming->start($id, static function (string $_, Cancellation $c): string {
            Async::sleep(EventLoop::current(), 10.0, $c);
            return 'Never saved';
        });
        EventLoop::current()->run();
        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertNull($store->title($id));
    }

    public function testTheAgentNamesItsSessionWithoutAppendingMessagesOrRendering(): void
    {
        [$store, $id] = $this->session();
        $model = $this->model('Automatic session naming');
        $renderer = $this->createMock(\Bia\Contracts\Renderer::class);
        $renderer->expects(self::never())->method('event');
        $renderer->expects(self::never())->method('finish');
        $before = $store->path($id);
        $agent = new Agent($model, 'm', new Registry(), $store, $renderer, '/workspace', title: new Title($model, 'm'));
        self::assertSame('Automatic session naming', $agent->title($id));
        self::assertEquals($before, $store->path($id));
    }

    public function testAnInterruptedTurnCannotBeNamedEvenAfterCompaction(): void
    {
        $call = new ToolCall('c', 'read_file', ['path' => 'src/Session.php']);
        foreach ([Message::user('Name sessions automatically'), new Message('assistant', 'Checking.', [$call]), Message::toolResult($call, 'file body', true)] as $last) {
            [$store, $id] = $this->session();
            $store->append($id, $last);
            $store->append($id, new Compaction('Working on session names.', 1, 1000, 100));
            $model = $this->model('Automatic session naming');
            $agent = new Agent($model, 'm', new Registry(), $store, $this->createStub(\Bia\Contracts\Renderer::class), '/workspace', title: new Title($model, 'm'));
            self::assertNull($agent->title($id));
            self::assertSame([], $model->requests, 'No naming request for an unanswered turn, even after /compact.');
            $store->append($id, new Message('assistant', 'Finished.'));
            self::assertSame('Automatic session naming', $agent->title($id));
        }
    }

    private function model(string $answer): Model
    {
        return new class ($answer) implements Model {
            /** @var list<ModelRequest> */
            public array $requests = [];

            public function __construct(private readonly string $answer) {}

            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->requests[] = $request;
                return new ModelResult($this->answer);
            }
        };
    }

    /** @return array{SqliteStore, string} */
    private function session(): array
    {
        $store = new SqliteStore(sys_get_temp_dir() . '/bia-title-' . bin2hex(random_bytes(4)) . '.sqlite');
        $id = $store->create('/workspace', 'm');
        $store->append($id, Message::user('hi'));
        $store->append($id, new Message('assistant', 'Hello!'));
        return [$store, $id];
    }

    /** @return list<Entry> */
    private static function entries(Message ...$messages): array
    {
        $entries = [];
        foreach ($messages as $index => $message) {
            $entries[] = new Entry($index + 1, $index === 0 ? null : $index, $message);
        }
        return $entries;
    }
}

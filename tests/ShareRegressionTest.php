<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Agent\Agent;
use Bia\Config\Config;
use Bia\Contracts\{Image, Message, Model, ModelEvent, ModelRequest, ModelResult, Renderer, Tool, ToolCall, ToolContext, ToolDefinition, ToolResult};
use Bia\Model\ModelCatalog;
use Bia\Session\SqliteStore;
use Bia\Share\{Records, Relay, Session};
use Bia\Terminal\{Chat, Files, History, TuiRenderer};
use Bia\Tool\{AskTool, ProcessRunner, Registry};
use Bia\Tui\{Key, Screen, Style, Terminal};
use Crab\Engine\Async\{Async, Cancellation, Task};
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\HttpClient\Client;
use PHPUnit\Framework\TestCase;

final class ShareRegressionTest extends TestCase
{
    private string $home;
    private string|false $oldHome;
    private array $chats = [];
    private array $outputs = [];

    protected function setUp(): void
    {
        if (!extension_loaded('openssl') || !in_array('chacha20', openssl_get_cipher_methods(), true)) { self::markTestSkipped('Sharing requires openssl with ChaCha20'); }
        EventLoop::install(new StreamSelectEventLoop());
        Style::enable(false);
        $this->oldHome = getenv('BIA_HOME');
        $this->home = sys_get_temp_dir() . '/bia-share-regression-' . bin2hex(random_bytes(6));
        mkdir($this->home, 0700);
        putenv('BIA_HOME=' . $this->home);
    }

    protected function tearDown(): void
    {
        if (!isset($this->home)) { return; }
        foreach ($this->chats as $chat) {
            (new \ReflectionProperty($chat, 'running'))->setValue($chat, false);
            $this->get($chat, 'turn')?->cancel();
            $this->invoke($chat, 'endShare');
        }
        EventLoop::install(new StreamSelectEventLoop());
        $this->chats = [];
        foreach ($this->outputs as $out) { fclose($out); }
        foreach (glob($this->home . '/*') as $file) { unlink($file); }
        rmdir($this->home);
        putenv($this->oldHome === false ? 'BIA_HOME' : 'BIA_HOME=' . $this->oldHome);
        Style::enable(false);
    }

    public function testGuestInputCannotInheritHostImagesIncludingOnResume(): void
    {
        $probe = new class implements Tool {
            public array $images = [];
            public function definition(): ToolDefinition { return new ToolDefinition('probe', 'Capture attachments', ['type' => 'object'], 'read'); }
            public function execute(array $arguments, ToolContext $context, ?Cancellation $cancellation = null): ToolResult
            {
                $this->images[] = $context->images;
                return new ToolResult('Captured');
            }
        };
        [$chat, $store, $id, $model, , $agent] = $this->chat((new Registry())->add($probe));
        $image = new Image($this->home . '/host.png', 'image/png');
        file_put_contents($image->path, "\x89PNG\r\n\x1a\nfixture");
        $store->append($id, Message::user('Host attachment', [$image]));
        $store->append($id, new Message('assistant', 'Seen'));
        (new \ReflectionProperty($chat, 'attached'))->setValue($chat, [1 => $image]);

        $model->batches = [[new ToolCall('local', 'probe', [])]];
        $agent->run($id, 'Local follow-up');
        self::assertEquals([$image], $probe->images[0], 'Local follow-ups still inherit attachments.');

        $model->batches = [[new ToolCall('remote', 'probe', [])]];
        $this->invoke($chat, 'shareReceive', ['type' => 'input', 'text' => 'Use [Image #1]']);
        EventLoop::current()->run();
        self::assertSame([], $probe->images[1], 'Neither a chip nor history grants a remote turn an upload.');
        $remote = array_values(array_filter($store->path($id), static fn ($entry): bool => str_contains($entry->message()?->text ?? '', '[Billy · remote]')))[0]->message();
        self::assertSame(false, $remote->meta['inherit_images']);
        self::assertSame([], $remote->images);

        // SqliteStore decoded the metadata from disk; a resumed request must keep the same boundary.
        $model->batches = [[new ToolCall('resumed', 'probe', [])]];
        $agent->resume($id);
        self::assertSame([], $probe->images[2]);
        $model->batches = [[new ToolCall('later', 'probe', [])]];
        $agent->run($id, 'Another image-free follow-up');
        self::assertSame([], $probe->images[3]);
        $model->batches = [[new ToolCall('new-image', 'probe', [])]];
        $agent->run($id, 'Host explicitly attaches again', images: [$image]);
        self::assertEquals([$image], $probe->images[4]);
    }

    public function testRevocationDismissesQuestionsAndPermissionPromptsButNotHostTurns(): void
    {
        foreach (['question', 'permission'] as $kind) {
            foreach (['local', 'peer'] as $stop) {
                foreach ([true, false] as $remote) {
                    $chat = null;
                    $tools = new Registry($kind === 'permission'
                        ? static function () use (&$chat): bool { return $chat->confirm('Allow this tool?'); } : null);
                    $tools->add(new AskTool(static function (string $question, array $options) use (&$chat): string|false|null { return $chat->choose($question, $options); }));
                    [$chat, , , $model, , , $host, $guest] = $this->chat($tools);
                    $model->batches = [[new ToolCall('question', 'ask', ['question' => 'Choose one', 'options' => [['label' => 'One'], ['label' => 'Two']]])]];
                    $this->invoke($chat, 'ask', 'Ask a question', $remote);
                    $this->tick();
                    $field = $kind === 'question' ? 'choosing' : 'confirming';
                    self::assertNotNull($this->get($chat, $field));
                    $turn = $this->get($chat, 'turn');
                    $lateAnswer = $this->get($chat, $field);
                    if ($stop === 'local') {
                        $this->invoke($chat, 'endShare');
                    } else {
                        $host->receive($guest->identity->seal($host->identity->publicKey(), ['v' => 1, 'token' => $host->invite->token, 'type' => 'stop']));
                    }
                    $this->tick();
                    $stillWaiting = $this->get($chat, $field) !== null;
                    // Cleanup even if the regression returns; a test must never hang on an unanswered prompt.
                    $turn->cancel();
                    $this->invoke($chat, 'handle', new Key('escape'));
                    $this->tick();
                    self::assertSame(!$remote, $stillWaiting, "$kind / $stop: only a guest-origin prompt is dismissed.");
                    self::assertNull($this->get($chat, 'turn'));
                    self::assertNull($this->get($chat, 'choosing'));
                    self::assertNull($this->get($chat, 'confirming'));
                    self::assertSame('', $this->get($chat, 'menuKind'));
                    self::assertCount(1, $model->requests, 'Cancellation cannot answer the model and continue the turn.');
                    $lateAnswer(true); // A queued key after cancellation is harmless, never a second resume.
                    $host->stop(); $guest->stop();
                }
            }
        }
    }

    public function testNormalQuestionPermissionAndTimeoutStillSettleOnce(): void
    {
        [$chat] = $this->chat(new Registry());
        foreach (['choose', 'confirm'] as $method) {
            $task = Task::spawn(EventLoop::current(), fn () => $method === 'choose'
                ? $chat->choose('Choose', [['label' => 'One', 'detail' => ''], ['label' => 'Two', 'detail' => '']]) : $chat->confirm('Allow?'));
            $this->tick();
            $this->invoke($chat, 'handle', new Key('enter'));
            self::assertSame($method === 'choose' ? 'One' : true, $task->await());
        }
        (new \ReflectionProperty($chat, 'config'))->setValue($chat, Config::load($this->home, overrides: ['tools' => ['ask_timeout' => 0.01]]));
        self::assertFalse($chat->choose('Timeout', [['label' => 'One', 'detail' => ''], ['label' => 'Two', 'detail' => '']]));
        self::assertNull($this->get($chat, 'choosing'));
    }

    public function testLiveAndHistoryEditsRetainOnlySanitizedDisplayData(): void
    {
        [$chat, $store, $id, , $renderer, , $host, $guest] = $this->chat(new Registry());
        $call = new ToolCall('edit-1', 'edit', ['path' => 'example.php']);
        $data = ['path' => 'example.php', 'diff' => ['@1', '-before', '+after'], 'private_metadata' => 'never export'];
        $result = new ToolResult('Edited example.php', true, $data);
        $renderer->tool($call, $result);
        $live = $guest->identity->open($host->packet())['payload']['records'][0];
        $store->append($id, new Message('assistant', toolCalls: [$call]));
        $store->append($id, Message::toolResult($call, $result->text, true, ['data' => $data, 'private' => 'not shared']));
        $history = array_values(array_filter(Records::history($store->path($id)), static fn (array $record): bool => $record['type'] === 'tool'))[0];
        self::assertSame($live, $history);
        self::assertSame(['path' => $data['path'], 'diff' => $data['diff']], $live['data']);
        self::assertStringNotContainsString('never export', json_encode($live));

        $received = $this->recorder();
        Records::play($live, $received, static fn () => null);
        self::assertSame($data['diff'], $received->results[0]->data['diff']);
        $lines = implode("\n", TuiRenderer::toolLines($call, true, $result->text, $received->results[0]->data, open: true));
        self::assertStringContainsString('(+1 −1)', $lines);
        self::assertStringContainsString('- before', $lines);
        self::assertStringContainsString('+ after', $lines);
        $legacy = $live;
        unset($legacy['data']);
        Records::play($legacy, $received, static fn () => null);
        self::assertSame([], $received->results[1]->data, 'Older peers without display data still render.');
    }

    public function testDiffDataIsBoundedAndSanitizedOnBothSides(): void
    {
        $call = new ToolCall('bounded', 'edit', ['path' => str_repeat('"', 1000)]);
        $result = new ToolResult(str_repeat('"', 8000), true, [
            'path' => "x\e]52;c;bad\x07.php", 'private' => 'not exported',
            'diff' => ['@1', "-before\e[31m\x07", "+after\u{202e}", ...array_fill(0, 200, '+' . str_repeat('"', 500))],
        ]);
        $record = Records::tool($call, $result);
        self::assertLessThanOrEqual(18000, strlen(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        self::assertLessThanOrEqual(4000, strlen(json_encode($record['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        self::assertTrue($record['data']['diff_truncated']);
        // A peer cannot bypass the sending-side sanitizer by supplying its own metadata.
        $record['data'] = $result->data;
        $received = $this->recorder();
        Records::play($record, $received, static fn () => null);
        $data = $received->results[0]->data;
        self::assertArrayNotHasKey('private', $data);
        self::assertStringNotContainsString("\e", implode('', $data['diff']) . $data['path']);
        self::assertStringNotContainsString("\u{202e}", implode('', $data['diff']));
        self::assertContains('Diff truncated for sharing', $received->notes);
    }

    public function testRunningRecordStaysBoundedAndDoesNotKillSession(): void
    {
        $call = new ToolCall('run-1', 'shell', ['command' => str_repeat('"', 1000)]);
        $record = Records::running($call, str_repeat('"', 8000));
        self::assertLessThanOrEqual(18000, strlen(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $host = new Session(true, static fn () => null, static fn () => null);
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite);
        try {
            $host->receive($guest->packet());
            $host->approve($guest->identity->publicKey());
            $host->publish($record);
            self::assertTrue($host->active(), 'Escaped running tool output must stay inside Session\'s packet limit.');
        } finally {
            $host->stop(); $guest->stop();
        }
    }

    public function testStreamingBurstCoalescesInsteadOfOverflowingOutbox(): void
    {
        $received = [];
        $host = new Session(true, static fn () => null, static fn () => null);
        $guest = new Session(false, static fn () => null, function (array $r) use (&$received): void { $received[] = $r; }, $host->invite);
        try {
            $host->receive($guest->packet());
            $host->approve($guest->identity->publicKey());
            for ($i = 0; $i < 400; $i++) {
                $host->publish(['type' => 'text', 'text' => "chunk-{$i} "]);
                $host->packet(); // Simulate repeated 0.5s ticks before the peer ACKs seq 1.
            }
            self::assertTrue($host->active(), 'Streaming chunks should coalesce in the batch instead of hitting the 256-packet cap.');
            $outbox = (new \ReflectionProperty($host, 'outbox'))->getValue($host);
            self::assertLessThan(10, count($outbox));
            for ($round = 0; $round < 20 && count($received) < 400; $round++) {
                $guest->receive($host->packet());
                $host->receive($guest->packet());
            }
            self::assertCount(400, $received);
            self::assertSame('chunk-399 ', $received[399]['text']);

            // Also verify >1,000 tiny records split into batches of at most 1,000 records.
            $finishes = 0;
            $received = [];
            for ($i = 0; $i < 1200; $i++) { $host->publish(['type' => 'finish']); }
            for ($round = 0; $round < 10 && $finishes < 1200; $round++) {
                $packet = $host->packet();
                $payload = $guest->identity->open($packet)['payload'];
                self::assertLessThanOrEqual(1000, count($payload['records']));
                $guest->receive($packet);
                $host->receive($guest->packet());
                $finishes = count($received);
            }
            self::assertSame(1200, $finishes);
        } finally {
            $host->stop(); $guest->stop();
        }
    }

    public function testRelayDoesNotReceiveDuplicateIdleEventsAndToleratesDuplicateOk(): void
    {
        $loop = EventLoop::current();
        $clients = [];
        $connections = [];
        $counts = [];
        $okMode = 'duplicate'; // 'duplicate' replies OK false duplicate: on first repeat; 'blocked' rejects all.
        $server = new WebSocketServer(function (WebSocketSession $socket, string $wire) use (&$clients, &$counts, &$okMode): void {
            $m = json_decode($wire, true);
            if (($m[0] ?? '') === 'REQ') {
                $clients[spl_object_id($socket)] = [$socket, $m[1], $m[2]];
                $socket->sendText(json_encode(['EOSE', $m[1]]));
            } elseif (($m[0] ?? '') === 'EVENT') {
                $e = $m[1];
                $id = $e['id'];
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                if ($okMode === 'blocked') {
                    $socket->sendText(json_encode(['OK', $id, false, 'blocked: policy']));
                    return;
                }
                // Even if the relay says duplicate: on an event, the session must stay connected.
                $socket->sendText(json_encode(['OK', $id, false, 'duplicate: already have this event']));
                foreach ($clients as [$peer, $sub, $filter]) {
                    if (!$peer->isClosed() && in_array($e['kind'], $filter['kinds'] ?? [], true)
                        && in_array($e['tags'][0][1] ?? '', $filter['#p'] ?? [], true)) {
                        $peer->sendText(json_encode(['EVENT', $sub, $e]));
                    }
                }
            }
        }, onClose: static function (WebSocketSession $socket) use (&$clients): void { unset($clients[spl_object_id($socket)]); });
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        stream_set_blocking($listener, false);
        $url = 'ws://' . stream_socket_get_name($listener, false);
        $watcher = $loop->onReadable($listener, static function () use ($loop, $listener, $server, &$connections): void {
            while (($stream = @stream_socket_accept($listener, 0)) !== false) {
                $conn = new Connection($loop, $stream);
                $id = spl_object_id($conn);
                $connections[$id] = $conn;
                $conn->onClose(static function () use (&$connections, $id): void { unset($connections[$id]); });
                $server->attach($conn);
            }
        });
        $host = new Session(true, static fn () => null, static fn () => null, relays: [$url]);
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite);
        try {
            $host->start();
            $guest->start();
            $deadline = microtime(true) + 10;
            while (!$host->approve($guest->identity->publicKey()) && microtime(true) < $deadline) {
                Task::spawn($loop, static fn () => Async::sleep($loop, 0.1))->await();
            }
            self::assertTrue($host->ready());
            // Let several 0.5s ticks elapse while idle: no event ID should be sent twice to the same relay connection.
            Task::spawn($loop, static fn () => Async::sleep($loop, 2.2))->await();
            self::assertSame('Connected', $host->relayStatus()[$url], 'A duplicate: OK reply must not drop the relay.');
            self::assertSame('Connected', $guest->relayStatus()[$url]);
            self::assertSame(1, max($counts), 'Idle ticks must not spam duplicate event IDs to a connected relay.');

            // A genuine rejection (non-duplicate OK false) still drops and marks the relay unavailable.
            $okMode = 'blocked';
            $host->publish(['type' => 'text', 'text' => 'trigger rejection']);
            $deadline = microtime(true) + 5;
            while ($host->relayStatus()[$url] === 'Connected' && microtime(true) < $deadline) {
                Task::spawn($loop, static fn () => Async::sleep($loop, 0.1))->await();
            }
            self::assertNotSame('Connected', $host->relayStatus()[$url]);
        } finally {
            $host->stop();
            $guest->stop();
            $loop->cancel($watcher);
            foreach ($connections as $conn) { $conn->close(); }
            fclose($listener);
        }
    }

    public function testRelayHostHeaderOmitsDefaultPortsAndKeepsExplicitPorts(): void
    {
        self::assertSame('relay.damus.io', Relay::hostHeader('wss://relay.damus.io'));
        self::assertSame('relay.damus.io', Relay::hostHeader('wss://relay.damus.io:443/nostr'));
        self::assertSame('relay.example:8443', Relay::hostHeader('wss://relay.example:8443/nostr'));
        self::assertSame('127.0.0.1', Relay::hostHeader('ws://127.0.0.1:80'));
        self::assertSame('127.0.0.1:39977', Relay::hostHeader('ws://127.0.0.1:39977'));
        self::assertSame('[::1]:8080', Relay::hostHeader('ws://[::1]:8080'));
    }

    private function chat(Registry $tools): array
    {
        $out = fopen('php://memory', 'w+');
        $this->outputs[] = $out;
        $terminal = new Terminal(STDIN, $out, [100, 32]);
        $surface = new Screen($terminal);
        $renderer = new TuiRenderer($surface);
        $store = new SqliteStore($this->home . '/session-' . count($this->chats) . '.sqlite');
        $id = $store->create($this->home, 'fixture');
        $model = new class implements Model {
            public array $batches = [];
            public array $requests = [];
            public function stream(ModelRequest $request, callable $emit, ?Cancellation $cancellation = null): ModelResult
            {
                $this->requests[] = $request;
                $calls = array_shift($this->batches) ?? [];
                return new ModelResult($calls === [] ? 'Done' : '', $calls === [] ? 'stop' : 'tool_calls', toolCalls: $calls);
            }
        };
        $agent = new Agent($model, 'fixture', $tools, $store, $renderer, $this->home);
        $config = Config::load($this->home, overrides: ['model' => ['api_key' => 'fixture', 'name' => 'fixture'], 'tools' => ['ask_timeout' => 0], 'recap' => ['auto' => false]]);
        $chat = new Chat($terminal, $surface, $renderer, $config, static fn () => $agent, $store, $id, '', $this->home,
            new Files(new ProcessRunner(), $this->home), new ModelCatalog(new Client()), new History($this->home . '/history'), 'test', $agent);
        $this->chats[] = $chat;
        $host = new Session(true, fn (string $text, bool $transient = false) => $this->invoke($chat, 'shareNotice', $text, $transient), static fn () => null, nickname: 'Host');
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite, nickname: 'Billy');
        $host->receive($guest->packet());
        $host->approve($guest->identity->publicKey());
        $guest->receive($host->packet());
        (new \ReflectionProperty($chat, 'sharing'))->setValue($chat, $host);
        $this->invoke($chat, 'observeShare');
        return [$chat, $store, $id, $model, $renderer, $agent, $host, $guest];
    }

    private function recorder(): Renderer
    {
        return new class implements Renderer {
            public array $results = [];
            public array $notes = [];
            public function event(ModelEvent $event): void {}
            public function running(ToolCall $call, string $output): void {}
            public function tool(ToolCall $call, ToolResult $result): void { $this->results[] = $result; }
            public function notice(string $message): void { $this->notes[] = $message; }
            public function finish(ModelResult $result): void {}
        };
    }

    private function tick(): void
    {
        Task::spawn(EventLoop::current(), static fn () => Async::sleep(EventLoop::current(), 0.01))->await();
    }

    private function get(Chat $chat, string $property): mixed { return (new \ReflectionProperty($chat, $property))->getValue($chat); }
    private function invoke(Chat $chat, string $method, mixed ...$arguments): mixed { return (new \ReflectionMethod($chat, $method))->invoke($chat, ...$arguments); }
}

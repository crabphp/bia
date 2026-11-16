<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Share\Discovery;
use Bia\Share\Identity;
use Bia\Share\Invite;
use Bia\Share\Nickname;
use Bia\Share\Records;
use Bia\Share\Session;
use Bia\Terminal\Loop;
use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class ShareTest extends TestCase
{
    private function crypto(): void
    {
        if (!extension_loaded('gmp') || !extension_loaded('sodium')) {
            self::markTestSkipped('Sharing requires gmp and sodium');
        }
    }

    public function testInvitesRoundTripAndContainNoPrivateKey(): void
    {
        $invite = new Invite(str_repeat('a', 64), str_repeat('b', 64), Invite::RELAYS, time() + 3600);
        self::assertEquals($invite, Invite::parse($invite->code()));
        self::assertStringStartsWith('bia1.', $invite->code());
    }

    public function testInvalidInvitesAndRelayUrlsAreRejected(): void
    {
        foreach (['', 'abc123', 'bia1.' . base64_encode('{}'), str_repeat('x', 3000)] as $code) {
            try { Invite::parse($code); self::fail('Accepted invalid invite'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach (['http://example.org', 'ws://example.org', 'wss://u:p@example.org', "wss://example.org/\r\nHost:x", 'wss://example.org/#secret'] as $url) {
            try { Invite::relay($url); self::fail('Accepted invalid relay'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testExpiredInviteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Invite(str_repeat('a', 64), str_repeat('b', 64), Invite::RELAYS, time() - 1);
    }

    public function testShortCodesAreExactlyTwelveRandomCaseSensitiveCharacters(): void
    {
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $code = Discovery::code();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{12}$/D', $code);
            $codes[] = $code;
        }
        self::assertCount(100, array_unique($codes));
        self::assertNotSame(Discovery::tag('a7K2m9Q4x8Lp'), Discovery::tag('a7k2m9q4x8lp'));
        foreach (['short', str_repeat('a', 13), 'a7K2m9Q4x8L!', "a7K2m9Q4x8Lp\n"] as $invalid) {
            try { Discovery::tag($invalid); self::fail('Accepted invalid code'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testDiscoveryAnnouncementIsEncryptedSignedAndBoundToItsCodeAndHost(): void
    {
        $this->crypto();
        $host = new Session(true, static fn () => null, static fn () => null);
        $verifier = new Identity();
        $event = Discovery::announce($host->identity, $host->invite, $host->code);
        self::assertEquals($host->invite, Discovery::open($event, $host->code, $verifier));
        foreach ([$host->code, $host->invite->code(), $host->invite->token] as $secret) {
            self::assertStringNotContainsString($secret, json_encode($event));
        }
        self::assertNull(Discovery::open($event, Discovery::code(), $verifier));
        foreach (['content' => 'broken', 'sig' => str_repeat('0', 128), 'id' => str_repeat('0', 64),
            'kind' => Identity::KIND, 'tags' => [], 'created_at' => time() - 1000, 'pubkey' => $verifier->publicKey()] as $key => $value) {
            self::assertNull(Discovery::open([$key => $value] + $event, $host->code, $verifier));
        }
        $this->expectException(\InvalidArgumentException::class);
        Discovery::announce($verifier, $host->invite, $host->code);
    }

    public function testDiscoveryTimeoutAndCancellationCloseTheirSockets(): void
    {
        $this->crypto();
        require_once __DIR__ . '/Fixtures/share-server.php';
        Loop::run(function (): void {
            $loop = EventLoop::current();
            $fixture = \shareFixture($loop);
            try {
                try {
                    Discovery::resolve(Discovery::code(), new \Crab\Engine\Async\Cancellation(), [$fixture['relay']], 0.3);
                    self::fail('Unknown invite was resolved');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Host not found', $error->getMessage());
                }
                Async::sleep($loop, 0.1);
                self::assertSame(0, ($fixture['clients'])(), 'Timed-out lookup left a socket open');
                $cancel = new \Crab\Engine\Async\Cancellation();
                $timer = $loop->delay(0.3, fn () => $cancel->cancel());
                try {
                    Discovery::resolve(Discovery::code(), $cancel, [$fixture['relay'], $fixture['relay']]);
                    self::fail('Canceled lookup returned');
                } catch (\Crab\Engine\Async\CancelledException) {
                    self::assertTrue($cancel->isCancelled());
                } finally {
                    $loop->cancel($timer);
                }
                Async::sleep($loop, 0.1);
                self::assertSame(0, ($fixture['clients'])(), 'Canceled lookup left a socket open');
            } finally {
                ($fixture['close'])();
            }
        });
    }

    public function testEncryptedSignedEnvelopeRejectsTamperingWrongRecipientAndStaleEvents(): void
    {
        $this->crypto();
        $a = new Identity(); $b = new Identity(); $c = new Identity();
        $e = $a->seal($b->publicKey(), ['text' => 'private tool output']);
        self::assertStringNotContainsString('private tool output', json_encode($e));
        self::assertSame(['text' => 'private tool output'], $b->open($e)['payload']);
        self::assertNull($c->open($e));
        foreach (['content' => 'corrupt', 'id' => str_repeat('0', 64), 'sig' => str_repeat('0', 128), 'created_at' => time() - 1000, 'tags' => []] as $key => $value) {
            self::assertNull($b->open([$key => $value] + $e));
        }
    }

    public function testApprovalIsRequiredAndOnlyPinnedGuestCanSend(): void
    {
        $this->crypto();
        $received = []; $notices = [];
        $host = new Session(true, function ($n) use (&$notices): void { $notices[] = $n; }, function ($r) use (&$received): void { $received[] = $r; });
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite);
        $host->receive($guest->packet());
        self::assertFalse($host->ready());
        self::assertNull($host->packet(), 'No history or welcome before approval');
        self::assertCount(1, $notices);
        self::assertFalse($host->approve('a'));
        self::assertTrue($host->approve(substr($guest->identity->publicKey(), 0, 12)));
        $guest->receive($host->packet());
        self::assertTrue($guest->ready());
        $guest->publish(['type' => 'input', 'text' => '/new is text, not a command']);
        $packet = $guest->packet();
        $host->receive($packet); $host->receive($packet);
        self::assertCount(1, $received);
        $outsider = new Identity();
        $host->receive($outsider->seal($host->identity->publicKey(), ['v' => 1, 'token' => $host->invite->token, 'type' => 'live', 'seq' => 2, 'ack' => 0, 'records' => [['type' => 'input', 'text' => 'intrusion']]]));
        self::assertCount(1, $received);
        $host->stop();
        $guest->publish(['type' => 'input', 'text' => 'after stop']);
        $host->receive($guest->packet());
        self::assertCount(1, $received);
    }

    public function testNicknamesAreBoundedUnverifiedSingleLineLabels(): void
    {
        foreach (['Billy', 'Đậu', '李明', "Anne-Marie O’Neil", 'billy_2', '32'] as $name) {
            self::assertSame($name, Nickname::valid($name));
        }
        self::assertSame('Billy', Nickname::valid(' Billy '));
        foreach (['', ' ', "Billy\nAllow", "Billy\t", "Billy\e[31m", "Bill\u{202e}y", "Bill\u{200b}y", '[Host]', str_repeat('a', 33), "\xff", []] as $name) {
            self::assertNull(Nickname::valid($name));
        }
    }

    public function testAdmissionSharesOnlyPinnedNicknamesUntilApprovalAndDenialIsFinal(): void
    {
        $this->crypto();
        $records = []; $notices = [];
        $host = new Session(true, static fn () => null, static fn () => null, nickname: 'Daud');
        $guest = new Session(false, function ($n) use (&$notices): void { $notices[] = $n; },
            function ($r) use (&$records): void { $records[] = $r; }, $host->invite, nickname: 'Billy');
        $key = $guest->identity->publicKey();
        $host->receive($guest->packet());
        self::assertSame('Billy', $host->pending()[$key]['nickname']);
        $host->publish(['type' => 'text', 'text' => 'private history']);
        $replies = $host->admissionPackets();
        self::assertCount(1, $replies);
        self::assertSame($replies, $host->admissionPackets(), 'Idle pre-admission replies are cached');
        $guest->receive($replies[0]);
        self::assertSame('Daud', $guest->peerNickname());
        self::assertFalse($guest->ready());
        self::assertSame([], $records);
        self::assertSame(['Waiting for Daud to approve…'], $notices);
        self::assertArrayNotHasKey('records', $guest->identity->open($replies[0])['payload']);
        $renamed = $guest->identity->seal($host->identity->publicKey(), ['v' => 1, 'type' => 'join',
            'token' => $host->invite->token, 'nickname' => 'Someone else']);
        $host->receive($renamed);
        self::assertSame('Billy', $host->pending()[$key]['nickname'], 'Same key cannot change the displayed approval name');
        self::assertTrue($host->deny($key));
        self::assertFalse($host->approve($key));
        $host->receive($guest->identity->seal($host->identity->publicKey(), ['v' => 1, 'type' => 'join',
            'token' => $host->invite->token, 'nickname' => 'Billy']));
        self::assertSame([], $host->pending());
        $guest->receive($host->admissionPackets()[0]);
        self::assertFalse($guest->active());
        self::assertSame([], $records);
        self::assertSame('Join request denied by host', end($notices));
    }

    public function testApprovalUsesPublicKeyNotNameAndPendingStopsAndExpiryRemoveAccess(): void
    {
        $this->crypto();
        $host = new Session(true, static fn () => null, static fn () => null, nickname: 'Daud');
        $one = new Session(false, static fn () => null, static fn () => null, $host->invite, nickname: 'Billy');
        $two = new Session(false, static fn () => null, static fn () => null, $host->invite, nickname: 'Billy');
        $host->receive($one->packet()); $host->receive($two->packet());
        self::assertCount(2, $host->pending());
        self::assertTrue($host->approve($one->identity->publicKey()));
        self::assertSame($one->identity->publicKey(), $host->peerKey());
        self::assertSame('Billy', $host->peerNickname());
        $one->receive($host->packet());
        self::assertSame('Daud', $one->peerNickname());
        $two->receive($host->admissionPackets()[0]);
        self::assertFalse($two->active());

        $host = new Session(true, static fn () => null, static fn () => null);
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite);
        $host->receive($guest->packet());
        $host->receive($guest->identity->seal($host->identity->publicKey(), ['v' => 1, 'type' => 'stop', 'token' => $host->invite->token]));
        self::assertSame([], $host->pending());
        self::assertFalse($host->approve($guest->identity->publicKey()));
        $host->receive($guest->identity->seal($host->identity->publicKey(), ['v' => 1, 'type' => 'join', 'token' => $host->invite->token]));
        $property = new \ReflectionProperty($host, 'requests');
        $requests = $property->getValue($host);
        foreach ($requests as &$request) { $request['at'] = time() - 121; }
        $property->setValue($host, $requests);
        self::assertSame([], $host->pending());
        self::assertFalse($host->approve($guest->identity->publicKey()));
    }

    public function testOrderingAcknowledgementsAndLostPackets(): void
    {
        $this->crypto();
        $received = [];
        $host = new Session(true, static fn () => null, static fn () => null);
        $guest = new Session(false, static fn () => null, function ($r) use (&$received): void { $received[] = $r; }, $host->invite);
        $host->receive($guest->packet());
        $host->approve($guest->identity->publicKey());
        $host->publish(['type' => 'text', 'text' => 'one']);
        $first = $host->packet(); // lost, then resent
        self::assertSame($first, $host->packet());
        $host->publish(['type' => 'text', 'text' => 'two']);
        $guest->receive($first);
        $host->receive($guest->packet());
        $second = $host->packet();
        $guest->receive($second);
        $guest->receive($first); // another relay delivers an old event
        self::assertSame(['one', 'two'], array_column($received, 'text'));
        $host->receive($guest->packet());
        $payload = $guest->identity->open($host->packet())['payload'];
        self::assertSame(0, $payload['seq']);
        self::assertSame([], $payload['records']);
    }

    public function testUnknownTokensNeverProduceAnAdmissionRequest(): void
    {
        $this->crypto();
        $notices = [];
        $host = new Session(true, function ($n) use (&$notices): void { $notices[] = $n; }, static fn () => null);
        $attacker = new Identity();
        $host->receive($attacker->seal($host->identity->publicKey(), ['v' => 1, 'type' => 'join', 'token' => str_repeat('0', 64)]));
        self::assertSame([], $notices);
        self::assertFalse($host->approve($attacker->publicKey()));
    }

    public function testLiveHostSteeringAndGuestMessagesKeepTheirOwnNameDuringRemoteTurn(): void
    {
        $this->crypto();
        EventLoop::install(new \Crab\Engine\EventLoop\StreamSelectEventLoop());
        $host = new Session(true, static fn () => null, static fn () => null, nickname: 'Daud');
        $guest = new Session(false, static fn () => null, static fn () => null, $host->invite, nickname: 'Billy');
        $host->receive($guest->packet());
        $host->approve($guest->identity->publicKey());
        $out = fopen('php://memory', 'w+');
        $surface = new \Bia\Tui\Screen(new \Bia\Tui\Terminal(STDIN, $out, [80, 24]));
        $reflection = new \ReflectionClass(\Bia\Terminal\Chat::class);
        $chat = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('region')->setValue($chat, $surface);
        $reflection->getProperty('sharing')->setValue($chat, $host);
        $reflection->getProperty('remoteTurn')->setValue($chat, true);
        try {
            $reflection->getMethod('echo')->invoke($chat, 'host steering a guest-origin turn');
            $reflection->getMethod('echo')->invoke($chat, "[Billy · remote]\nremote message", true);
            $records = $guest->identity->open($host->packet())['payload']['records'];
            self::assertSame(["[Daud · host]\nhost steering a guest-origin turn", "[Billy · remote]\nremote message"], array_column($records, 'text'));
        } finally {
            $host->stop(); $guest->stop();
            EventLoop::install(new \Crab\Engine\EventLoop\StreamSelectEventLoop());
            fclose($out);
        }
    }

    public function testDisplaySanitizationIsBoundedAndRemovesTerminalControls(): void
    {
        self::assertStringNotContainsString("\033", Records::clean("\033]52;c;stolen\007"));
        self::assertSame("a\nb\tc", Records::clean("a\nb\tc"));
        self::assertStringContainsString('truncated for sharing', Records::clean(str_repeat('猫', 4000)));
    }

    public function testGuestInputCannotInvokeLocalCommandsOrEnterHistory(): void
    {
        EventLoop::install(new \Crab\Engine\EventLoop\StreamSelectEventLoop());
        $out = fopen('php://memory', 'w+');
        $surface = new \Bia\Tui\Screen(new \Bia\Tui\Terminal(STDIN, $out, [80, 24]));
        $reflection = new \ReflectionClass(\Bia\Terminal\Chat::class);
        $chat = $reflection->newInstanceWithoutConstructor();
        $path = sys_get_temp_dir() . '/bia-share-history-' . bin2hex(random_bytes(6));
        $history = new \Bia\Terminal\History($path);
        $editor = new \Bia\Tui\Editor();
        $called = [];
        $commands = new \Bia\Terminal\Commands();
        foreach (['new', 'join', 'model', 'reload'] as $name) {
            $commands->add($name, 'Test', function () use (&$called, $name): void { $called[] = $name; });
        }
        foreach (['editor' => $editor, 'history' => $history, 'region' => $surface,
            'renderer' => new \Bia\Terminal\TuiRenderer($surface), 'mcp' => null,
            'guest' => true, 'commands' => $commands] as $name => $value) {
            $reflection->getProperty($name)->setValue($chat, $value);
        }
        try {
            foreach (['/new', '/join secret-invite', '/model other', '/reload', 'do not run locally'] as $text) {
                $reflection->getMethod('submit')->invoke($chat, $text);
            }
            self::assertSame([], $called);
            self::assertSame([], $history->load());
            self::assertSame([], (new \ReflectionProperty($editor, 'history'))->getValue($editor));
            $reflection->getProperty('guest')->setValue($chat, false);
            $reflection->getProperty('turn')->setValue($chat, new \Crab\Engine\Async\Cancellation());
            $reflection->getMethod('submit')->invoke($chat, '/join secret-invite');
            $reflection->getMethod('submit')->invoke($chat, '/join a7K2m9Q4x8Lp');
            self::assertSame([], $history->load(), 'Invites never enter host input history either');
            self::assertSame([], (new \ReflectionProperty($editor, 'history'))->getValue($editor));
        } finally {
            if (is_file($path)) { unlink($path); }
            EventLoop::install(new \Crab\Engine\EventLoop\StreamSelectEventLoop());
            fclose($out);
        }
    }

    public function testLeavingOrCancelingLookupCannotAttachAStaleSessionOrEnableLocalInput(): void
    {
        $this->crypto();
        require_once __DIR__ . '/Fixtures/share-server.php';
        Loop::run(function (): void {
            $loop = EventLoop::current();
            $fixture = \shareFixture($loop);
            $out = fopen('php://memory', 'w+');
            $renderer = new \Bia\Terminal\TuiRenderer(new \Bia\Tui\Screen(new \Bia\Tui\Terminal(STDIN, $out, [80, 24])));
            $chat = new class($renderer) {
                use \Bia\Terminal\Sharing { joinShare as public join; endShare as public leave; }
                public array $notes = [];
                private $turn = null;
                private $pluginRun = null;
                private $naming = null;
                private bool $running = true;
                private string $menuKind = '';
                public function __construct(private \Bia\Terminal\TuiRenderer $renderer) { $this->shareNickname = 'Billy'; }
                private function note(string $text): void { $this->notes[] = $text; }
                private function say(string $text): void { $this->notes[] = $text; }
                private function view(mixed $view): void {}
                private function cancelRecap(): void {}
                private function draw(): void {}
                public function state(): array { return [$this->guest, $this->joining !== null, $this->sharing !== null]; }
                public function cancel(): void { $this->joining?->cancel(); }
            };
            try {
                $chat->join(Discovery::code() . ' ' . $fixture['relay']);
                self::assertSame([true, true, false], $chat->state(), 'Local input must be gated during lookup');
                $chat->leave(); // Cancel before the lookup task gets its first turn.
                self::assertSame([false, false, false], $chat->state());
                $chat->join(Discovery::code() . ' ' . $fixture['relay']);
                Async::sleep($loop, 0.2);
                self::assertSame([true, true, false], $chat->state(), 'Old lookup completion touched its replacement');
                self::assertCount(0, $chat->notes, 'Lookup status stays out of the conversation');
                $chat->cancel();
                Async::sleep($loop, 0.1);
                self::assertSame([true, false, false], $chat->state(), 'Cancellation must not enable local execution');
                self::assertStringContainsString('Join canceled', end($chat->notes));
                self::assertSame(0, ($fixture['clients'])());
                $chat->leave();
            } finally {
                $chat->leave();
                ($fixture['close'])();
                fclose($out);
            }
        });
    }

    public function testWebSocketFramesAreFragmentedBoundedAndValidated(): void
    {
        $messages = [];
        $relay = new \Bia\Share\Relay(function ($m) use (&$messages): void { $messages[] = $m; });
        $feed = new \ReflectionMethod($relay, 'feed');
        $wire = "\x01\x04[\"te" . "\x80\x04st\"]";
        foreach (str_split($wire) as $byte) { $feed->invoke($relay, $byte); }
        self::assertSame([['test']], $messages);
        $this->expectException(\RuntimeException::class);
        $feed->invoke($relay, "\x81\x80"); // A server must not mask its frames.
    }

    public function testLiveRelayCarriesApprovedHistoryAndGuestInput(): void
    {
        $this->crypto();
        require_once __DIR__ . '/Fixtures/share-server.php';
        Loop::run(function (): void {
            $loop = EventLoop::current();
            $fixture = \shareFixture($loop);
            $hostRecords = []; $guestRecords = []; $requests = [];
            $host = new Session(true, function ($n) use (&$requests): void { $requests[] = $n; }, function ($r) use (&$hostRecords): void { $hostRecords[] = $r; }, relays: [$fixture['relay']]);
            $guest = null;
            try {
                $host->start();
                ($fixture['forge'])(); // A bad signature with the same id must not poison discovery's deduplication.
                $invite = Discovery::resolve($host->code, new \Crab\Engine\Async\Cancellation(), [$fixture['relay']]);
                self::assertEquals($host->invite, $invite);
                self::assertFalse($host->ready(), 'Finding a code is not approval');
                $guest = new Session(false, static fn () => null, function ($r) use (&$guestRecords): void { $guestRecords[] = $r; }, $invite);
                $guest->start();
                $deadline = microtime(true) + 20;
                while (!$host->approve($guest->identity->publicKey()) && microtime(true) < $deadline) { Async::sleep($loop, 0.1); }
                self::assertTrue($host->ready(), implode('\n', $requests));
                ($fixture['drop'])($host->identity->publicKey()); // Lose the guest's ACK, while the relay remembers its event id.
                $host->publish(['type' => 'text', 'text' => 'history']);
                while ($guestRecords === [] && microtime(true) < $deadline) { Async::sleep($loop, 0.1); }
                self::assertSame('history', $guestRecords[0]['text'] ?? null);
                self::assertSame('connected', $host->status()['state']);
                self::assertContains('Connected', $guest->relayStatus());
                self::assertSame([], array_values(array_filter($requests, static fn (string $n): bool => str_starts_with($n, 'Share relay'))), 'Relay diagnostics must not become conversation notices');
                ($fixture['disconnect'])();
                Async::sleep($loop, 0.1);
                self::assertSame('waiting', $host->status()['state']);
                ($fixture['drop'])('');
                $host->publish(['type' => 'text', 'text' => 'after reconnect']);
                $deadline = microtime(true) + 40;
                while (count($guestRecords) < 2 && microtime(true) < $deadline) { Async::sleep($loop, 0.1); }
                self::assertSame(['history', 'after reconnect'], array_column($guestRecords, 'text'), 'Lost ACK and reconnect must not duplicate history');
                $guest->publish(['type' => 'input', 'text' => 'debug this']);
                while ($hostRecords === [] && microtime(true) < $deadline) { Async::sleep($loop, 0.1); }
                self::assertSame('debug this', $hostRecords[0]['text'] ?? null);
                $host->stop();
                // Stop is best effort; no future input is ever delivered by a stopped host.
                self::assertFalse($host->active());
                self::assertSame('disconnected', $host->status()['state']);
            } finally {
                $host->stop(); $guest?->stop(); ($fixture['close'])();
            }
        });
    }
}

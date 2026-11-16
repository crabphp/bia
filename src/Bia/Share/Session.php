<?php

declare(strict_types=1);

namespace Bia\Share;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/** One host, one approved guest; ordered, acknowledged delivery over untrusted ephemeral relays. */
final class Session
{
    public readonly Identity $identity;
    public readonly Invite $invite;
    public readonly ?string $code;
    private float $announcedAt = 0;
    private ?string $peer = null;
    private array $requests = [];
    private array $denied = [];
    private array $admissions = [];
    private ?string $peerNickname = null;
    public readonly string $nickname;
    private array $outbox = [];
    private array $batch = [];
    private int $batchBytes = 0;
    private int $queuedBytes = 0;
    private int $sent = 0;
    private int $received = 0;
    private float $lastPeer;
    private array $seen = [];
    private array $incoming = [];
    private array $relays = [];
    private array $relayStates = [];
    private bool $connectedOnce = false;
    private ?Cancellation $cancel = null;
    private bool $closed = false;
    private bool $welcomed = false;
    private string $lastPayload = '';
    private ?array $lastPacket = null;
    private float $packetAt = 0;

    /** Callbacks never suspend: they enqueue work or draw through the Surface. */
    public function __construct(public readonly bool $hosting, private readonly \Closure $notice,
        private readonly \Closure $deliver, ?Invite $invite = null, array $relays = Invite::RELAYS, string $nickname = '')
    {
        $this->nickname = Nickname::valid($nickname) ?? ($hosting ? 'Host' : 'Guest');
        $this->identity = new Identity();
        $this->invite = $invite ?? new Invite($this->identity->publicKey(), bin2hex(random_bytes(32)), $relays, time() + 3600);
        $this->code = $hosting ? Discovery::code() : null;
        $this->relayStates = array_fill_keys($this->invite->relays, 'Connecting');
        $this->lastPeer = microtime(true);
        if (!$hosting) {
            $this->peer = $this->invite->host;
        }
    }

    public function active(): bool { return !$this->closed; }
    public function ready(): bool { return !$this->closed && $this->peer !== null && ($this->hosting || $this->welcomed); }

    public function peerNickname(): string { return $this->peerNickname ?? ($this->hosting ? 'Guest' : 'Host'); }
    public function peerKey(): ?string { return $this->peer; }

    /** Structured connection state for the local footer and on-demand diagnostics, never conversation output. */
    public function relayStatus(): array
    {
        $states = $this->relayStates;
        foreach ($states as $url => $state) {
            if ($this->closed) { $states[$url] = 'Disconnected'; }
            elseif ($state === 'Connected' && ($this->relays[$url] ?? null)?->alive() !== true) { $states[$url] = 'Reconnecting'; }
        }
        return $states;
    }

    /** @return array{state: string, text: string} */
    public function status(): array
    {
        if ($this->closed) { return ['state' => 'disconnected', 'text' => 'Disconnected · /share stop to leave']; }
        if (!in_array('Connected', $this->relayStatus(), true)) {
            return ['state' => 'waiting', 'text' => $this->connectedOnce ? 'Reconnecting…' : 'Connecting to relay…'];
        }
        if ($this->ready()) {
            if (microtime(true) - $this->lastPeer > 30) { return ['state' => 'waiting', 'text' => 'Reconnecting to ' . $this->peerNickname() . '…']; }
            return ['state' => 'connected', 'text' => 'Sharing with ' . $this->peerNickname()];
        }
        if (!$this->hosting) {
            return ['state' => 'waiting', 'text' => $this->peerNickname === null ? 'Waiting for host approval…' : 'Waiting for ' . $this->peerNickname . ' to approve…'];
        }
        $pending = $this->pending();
        return $pending === [] ? ['state' => 'connected', 'text' => 'Sharing · waiting for guest']
            : ['state' => 'waiting', 'text' => reset($pending)['nickname'] . ' wants to join'];
    }

    /** Live requests, keyed by authenticated public key, with names pinned on first receipt. */
    public function pending(): array
    {
        if ($this->closed || $this->peer !== null || time() >= $this->invite->expires) { return []; }
        return array_filter($this->requests, static fn (array $request): bool => time() - $request['at'] < 120);
    }

    public function deny(string $key): bool
    {
        if (!isset($this->pending()[$key])) { return false; }
        unset($this->requests[$key], $this->admissions[$key]);
        $this->denied[$key] = time();
        return true;
    }

    /** Small, encrypted pre-admission replies carry names/status only, never history or records. */
    public function admissionPackets(): array
    {
        if (!$this->hosting || $this->closed || time() >= $this->invite->expires) { return []; }
        $packets = [];
        $pending = $this->pending();
        foreach (array_unique([...array_keys($pending), ...array_keys($this->denied)]) as $key) {
            if (isset($this->denied[$key]) && time() - $this->denied[$key] >= 120) { continue; }
            $type = isset($pending[$key]) ? 'pending' : 'denied';
            $cached = $this->admissions[$key] ?? null;
            if ($cached === null || $cached['type'] !== $type || microtime(true) - $cached['at'] >= 10) {
                $cached = $this->admissions[$key] = ['type' => $type, 'at' => microtime(true),
                    'event' => $this->identity->seal($key, ['v' => 1, 'token' => $this->invite->token,
                        'type' => $type, 'nickname' => $this->nickname])];
            }
            $packets[] = $cached['event'];
        }
        return $packets;
    }

    public function start(): void
    {
        $this->cancel = new Cancellation();
        $loop = EventLoop::current();
        foreach (array_unique($this->invite->relays) as $url) {
            Task::spawn($loop, function () use ($loop, $url): void {
                while (!$this->closed) {
                    $relay = new Relay(function (array $message): void {
                        if (($message[0] ?? null) === 'EVENT' && ($message[1] ?? null) === 'bia' && is_array($message[2] ?? null)) {
                            if (count($this->incoming) < 32) {
                                $this->incoming[] = $message[2];
                            }
                        } elseif (($message[0] ?? null) === 'CLOSED' || ($message[0] ?? null) === 'AUTH'
                            || (($message[0] ?? null) === 'OK' && ($message[2] ?? null) === false)) {
                            // Never echo arbitrary relay text (or authentication challenges) into the terminal.
                            throw new \RuntimeException('Relay rejected sharing events');
                        }
                    });
                    try {
                        $relay->connect($url, $this->cancel);
                        $relay->send(['REQ', 'bia', ['kinds' => [Identity::KIND], '#p' => [$this->identity->publicKey()], 'since' => time() - 120]]);
                        $this->relays[$url] = $relay;
                        $this->relayStates[$url] = 'Connected';
                        $this->connectedOnce = true;
                        while (!$this->closed && $relay->alive()) {
                            Async::sleep($loop, 10, $this->cancel);
                            $relay->ping();
                        }
                    } catch (\Throwable) {
                        $this->relayStates[$url] = 'Unavailable or rejected events · retrying';
                    } finally {
                        unset($this->relays[$url]);
                        if ($this->relayStates[$url] === 'Connected') { $this->relayStates[$url] = 'Reconnecting'; }
                        $relay->close();
                    }
                    try { Async::sleep($loop, 5, $this->cancel); } catch (\Throwable) { return; }
                }
            });
        }
        Task::spawn($loop, function () use ($loop): void {
            try {
                while (!$this->closed) {
                    // Bound expensive signature verification per tick; low-level socket callbacks never run crypto.
                    for ($i = 0; $i < 4 && $this->incoming !== []; $i++) {
                        $this->receive(array_shift($this->incoming));
                    }
                    if (time() >= $this->invite->expires || (($this->ready() || $this->peerNickname !== null) && microtime(true) - $this->lastPeer > 90)) {
                        $this->stop();
                        ($this->notice)('Share ended · invite expired or peer disconnected');
                        return;
                    }
                    if ($this->relays !== []) {
                        foreach ($this->admissionPackets() as $admission) { $this->broadcast($admission); }
                        if ($this->hosting && $this->peer === null && microtime(true) - $this->announcedAt >= 5) {
                            $this->broadcast(Discovery::announce($this->identity, $this->invite, $this->code));
                            $this->announcedAt = microtime(true);
                        }
                        $packet = $this->packet();
                        if ($packet !== null) {
                            $this->broadcast($packet);
                        }
                    }
                    Async::sleep($loop, 0.5, $this->cancel);
                }
            } catch (\Throwable) {
                if (!$this->closed) {
                    $this->stop();
                    ($this->notice)('Share ended · transport failed');
                }
            }
        });
    }

    public function approve(string $fingerprint): bool
    {
        if (!$this->hosting || $this->peer !== null || $this->closed) { return false; }
        foreach ($this->pending() as $key => $request) {
            if (strlen($fingerprint) >= 12 && str_starts_with($key, $fingerprint)) {
                $this->peer = $key;
                $this->peerNickname = $request['nickname'];
                $this->lastPeer = microtime(true);
                foreach ($this->requests as $other => $_) {
                    if ($other !== $key) { $this->denied[$other] = time(); }
                }
                $this->requests = [];
                $this->admissions = [];
                return true;
            }
        }
        return false;
    }

    /** Buffered without network I/O on the model/rendering path. */
    public function publish(array $record): void
    {
        if (!$this->ready()) { return; }
        $bytes = strlen(json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($bytes > 18000) {
            $this->stop();
            ($this->notice)('Share ended · record exceeds transport limit');
            return;
        }
        if ($this->batchBytes + $bytes > 18000) { $this->flush(); }
        $this->batch[] = $record;
        $this->batchBytes += $bytes;
        if ($this->queuedBytes + $this->batchBytes > 2 * 1024 * 1024 || count($this->outbox) > 256) {
            $this->stop();
            ($this->notice)('Share ended · peer fell too far behind');
        }
    }

    private function flush(): void
    {
        if ($this->batch === []) { return; }
        $this->outbox[++$this->sent] = ['records' => $this->batch, 'bytes' => $this->batchBytes];
        $this->queuedBytes += $this->batchBytes;
        $this->batch = [];
        $this->batchBytes = 0;
    }

    /** Exposed independently of sockets for deterministic protocol tests. */
    public function packet(): ?array
    {
        if ($this->closed || $this->peer === null) { return null; }
        $this->flush();
        $sequence = array_key_first($this->outbox);
        $payload = [
            'v' => 1, 'token' => $this->invite->token, 'nickname' => $this->nickname,
            'type' => $this->hosting ? 'welcome' : ($this->welcomed ? 'live' : 'join'),
            'ack' => $this->received, 'seq' => $sequence ?? 0,
            'records' => $sequence === null ? [] : $this->outbox[$sequence]['records'],
        ];
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($this->lastPacket === null || $this->lastPayload !== $serialized || microtime(true) - $this->packetAt >= 10) {
            $this->lastPacket = $this->identity->seal($this->peer, $payload);
            $this->lastPayload = $serialized;
            $this->packetAt = microtime(true);
        }
        return $this->lastPacket;
    }

    public function receive(array $event): void
    {
        if ($this->closed || time() >= $this->invite->expires) { return; }
        $id = $event['id'] ?? null;
        if (!is_string($id) || isset($this->seen[$id])) { return; }
        if ($this->peer !== null && ($event['pubkey'] ?? null) !== $this->peer) { return; }
        $opened = $this->identity->open($event);
        if ($opened === null) { return; }
        $this->seen[$id] = true;
        if (count($this->seen) > 512) { array_shift($this->seen); }
        ['sender' => $sender, 'payload' => $p] = $opened;
        if (($p['v'] ?? null) !== 1 || !is_string($p['token'] ?? null) || !hash_equals($this->invite->token, $p['token'])
            || !in_array($p['type'] ?? null, ['join', 'pending', 'denied', 'welcome', 'live', 'stop'], true)) { return; }
        if ($this->hosting && $this->peer === null) {
            if ($p['type'] === 'stop') {
                unset($this->requests[$sender], $this->admissions[$sender]);
                return;
            }
            if ($p['type'] !== 'join' || isset($this->denied[$sender])) { return; }
            $this->requests = $this->pending();
            $this->admissions = array_intersect_key($this->admissions, $this->requests + $this->denied);
            if (isset($this->requests[$sender])) {
                $this->requests[$sender]['at'] = time();
            } elseif (count($this->requests) < 4 && count($this->denied) < 16) {
                $name = Nickname::valid($p['nickname'] ?? null) ?? 'Guest';
                $this->requests[$sender] = ['at' => time(), 'nickname' => $name];
                ($this->notice)($name . ' wants to join', true);
            }
            return;
        }
        if ($sender !== $this->peer) { return; }
        $this->lastPeer = microtime(true);
        if ($p['type'] === 'stop') {
            $this->stop(false);
            ($this->notice)($this->peerNickname() . ' left');
            return;
        }
        if (!$this->hosting && !$this->welcomed) {
            if ($p['type'] === 'denied') {
                $this->stop(false);
                ($this->notice)('Join request denied by host');
                return;
            }
            if ($p['type'] === 'pending') {
                if ($this->peerNickname === null) {
                    $this->peerNickname = Nickname::valid($p['nickname'] ?? null) ?? 'Host';
                    ($this->notice)('Waiting for ' . $this->peerNickname . ' to approve…', true);
                }
                return;
            }
            if ($p['type'] !== 'welcome') { return; }
            $this->peerNickname ??= Nickname::valid($p['nickname'] ?? null) ?? 'Host';
            $this->welcomed = true;
            ($this->notice)('Joined ' . $this->peerNickname . ' · messages run on the host · /share stop to leave');
        }
        if (in_array($p['type'], ['pending', 'denied'], true)) { return; }
        $ack = $p['ack'] ?? null;
        $seq = $p['seq'] ?? null;
        if (!is_int($ack) || $ack < 0 || $ack > $this->sent || !is_int($seq) || $seq < 0
            || !is_array($p['records'] ?? null) || count($p['records']) > 1000) { return; }
        foreach ($this->outbox as $number => $packet) {
            if ($number <= $ack) {
                $this->queuedBytes -= $packet['bytes'];
                unset($this->outbox[$number]);
            }
        }
        if ($seq !== $this->received + 1) { return; }
        // Advance before delivering: a repeated event must never repeat a model/tool mutation.
        $this->received = $seq;
        foreach ($p['records'] as $record) {
            if (is_array($record)) { ($this->deliver)($record); }
        }
    }

    private function broadcast(array $event): void
    {
        foreach ($this->relays as $relay) {
            try { $relay->send(['EVENT', $event]); } catch (\Throwable) { $relay->close(); }
        }
    }

    public function stop(bool $notify = true): void
    {
        if ($this->closed) { return; }
        if ($notify && $this->relays !== []) {
            foreach ($this->peer !== null ? [$this->peer] : array_keys($this->pending()) as $recipient) {
                try { $this->broadcast($this->identity->seal($recipient, ['v' => 1, 'token' => $this->invite->token, 'type' => 'stop'])); } catch (\Throwable) {}
            }
        }
        $this->closed = true;
        $this->cancel?->cancel();
        foreach ($this->relays as $relay) { $relay->close(); }
        $this->relays = $this->outbox = $this->incoming = $this->batch = $this->requests = $this->denied = $this->admissions = [];
    }
}

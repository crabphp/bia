<?php

declare(strict_types=1);

namespace Bia\Share;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\WriteResult;

/** Bounded RFC 6455 client over Crab's cancellable connector (its WebSocket session is server-only). */
final class Relay
{
    private string $buffer = '';
    private string $fragment = '';
    private bool $fragmenting = false;
    private ?Connection $connection = null;
    private float $received = 0;

    public function __construct(private readonly \Closure $message) {}

    public function connect(string $url, Cancellation $cancel): void
    {
        Invite::relay($url);
        $p = parse_url($url);
        $secure = $p['scheme'] === 'wss';
        $host = $p['host'];
        $port = $p['port'] ?? ($secure ? 443 : 80);
        $loop = EventLoop::current();
        $connection = $this->connection = (new Connector())->connect($loop, trim($host, '[]'), $port,
            hrtime(true) / 1e9 + 10, $cancel, $secure ? trim($host, '[]') : null);
        $key = base64_encode(random_bytes(16));
        $path = ($p['path'] ?? '/') ?: '/';
        $path .= isset($p['query']) ? '?' . $p['query'] : '';
        try {
            Async::await($loop, function (\Closure $resume, \Closure $fail) use ($loop, $connection, $key, $host, $port, $path): \Closure {
                $timer = $loop->delay(10, fn () => $fail(new \RuntimeException('Relay handshake timed out')));
                $connection->onClose(fn () => $fail(new \RuntimeException('Relay closed during handshake')));
                $connection->onData(function (Connection $_, string $bytes) use ($key, $resume, $fail, $connection): void {
                    $this->buffer .= $bytes;
                    $end = strpos($this->buffer, "\r\n\r\n");
                    if ($end === false) {
                        if (strlen($this->buffer) > 16384) {
                            $fail(new \RuntimeException('Relay headers too large'));
                        }
                        return;
                    }
                    $head = substr($this->buffer, 0, $end);
                    $this->buffer = substr($this->buffer, $end + 4);
                    $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    if ($end > 16384 || !preg_match('~^HTTP/1\.[01] 101 ~', $head)
                        || !preg_match('/^Sec-WebSocket-Accept:\s*' . preg_quote($expected, '/') . '\s*$/mi', $head)
                        || !preg_match('/^Upgrade:\s*websocket\s*$/mi', $head)
                        || !preg_match('/^Connection:.*\bupgrade\b/mi', $head)
                        || preg_match('/^Sec-WebSocket-(?:Extensions|Protocol):/mi', $head)) {
                        $fail(new \RuntimeException('Relay refused WebSocket upgrade'));
                        return;
                    }
                    $connection->pauseRead();
                    $resume();
                });
                $request = "GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nUser-Agent: Bia\r\n\r\n";
                $connection->tryWrite($request);
                return fn () => $loop->cancel($timer);
            }, $cancel);
            $this->received = microtime(true);
            $connection->onData(function (Connection $_, string $bytes): void {
                try {
                    $this->feed($bytes);
                } catch (\Throwable) {
                    $this->close();
                }
            });
            $connection->onEof(fn () => $this->close());
            $this->feed('');
            $connection->resumeRead();
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function alive(): bool
    {
        return $this->connection !== null && !$this->connection->isClosed() && microtime(true) - $this->received < 60;
    }

    public function ping(): void
    {
        $this->frame('', 9);
    }

    public function send(array $message): void
    {
        $this->frame(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 1);
    }

    private function frame(string $payload, int $opcode): void
    {
        $size = strlen($payload);
        if ($size > 65535) {
            throw new \LengthException('Relay frame too large');
        }
        $mask = random_bytes(4);
        $header = chr(0x80 | $opcode) . ($size < 126 ? chr(0x80 | $size) : chr(0xfe) . pack('n', $size));
        $masked = $payload ^ substr(str_repeat($mask, (int) ceil($size / 4)), 0, $size);
        if ($this->connection?->tryWrite($header . $mask . $masked) !== WriteResult::Accepted) {
            $this->close();
            throw new \RuntimeException('Relay write queue full or disconnected');
        }
    }

    private function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
        if (strlen($this->buffer) > 262144) {
            throw new \LengthException('Relay buffer full');
        }
        $frames = 0;
        while (strlen($this->buffer) >= 2) {
            if (++$frames > 256) {
                throw new \RuntimeException('Relay flooded frames');
            }
            $a = ord($this->buffer[0]);
            $b = ord($this->buffer[1]);
            $fin = ($a & 128) !== 0;
            $opcode = $a & 15;
            $length = $b & 127;
            $header = 2;
            if (($a & 112) !== 0 || ($b & 128) !== 0 || $length === 127 || !in_array($opcode, [0, 1, 8, 9, 10], true)) {
                throw new \RuntimeException('Invalid relay frame');
            }
            if ($length === 126) {
                if (strlen($this->buffer) < 4) {
                    return;
                }
                $length = unpack('n', substr($this->buffer, 2, 2))[1];
                $header = 4;
                if ($length < 126) {
                    throw new \RuntimeException('Noncanonical relay frame');
                }
            }
            if ($opcode >= 8 && (!$fin || $length > 125)) {
                throw new \RuntimeException('Invalid relay control frame');
            }
            if (strlen($this->buffer) < $header + $length) {
                return;
            }
            $data = substr($this->buffer, $header, $length);
            $this->buffer = substr($this->buffer, $header + $length);
            $this->received = microtime(true);
            if ($opcode === 8) {
                $this->close();
                return;
            }
            if ($opcode === 9) {
                $this->frame($data, 10);
                continue;
            }
            if ($opcode === 10) {
                continue;
            }
            if (($opcode === 0) !== $this->fragmenting) {
                throw new \RuntimeException('Invalid relay continuation');
            }
            $this->fragment .= $data;
            $this->fragmenting = !$fin;
            if (strlen($this->fragment) > 65535) {
                throw new \LengthException('Relay message too large');
            }
            if ($fin) {
                $message = json_decode($this->fragment, true, 32);
                $this->fragment = '';
                if (is_array($message)) {
                    ($this->message)($message);
                }
            }
        }
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null;
    }
}

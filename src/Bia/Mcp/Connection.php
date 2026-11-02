<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

/**
 * JSON-RPC with one MCP server: requests matched to their answers, the
 * server's own requests answered, its notifications heard.
 *
 * Bia offers nothing back but answers to pings: no sampling, no roots, no
 * elicitation. A request waits at most its timeout, and a cancelled one is
 * cancelled on the server too.
 */
final class Connection
{
    /** The protocol version asked for; a server may answer with an older one it speaks. */
    public const VERSION = '2025-06-18';

    private int $next = 1;
    /** @var array<int, Suspension> */
    private array $waiting = [];
    private ?string $gone = null;
    /** @var \Closure(): void|null */
    private ?\Closure $toolsChanged = null;
    /** @var \Closure(string): void|null */
    private ?\Closure $closed = null;

    public function __construct(private readonly Transport $transport) {}

    /**
     * Starts talking: the handshake, as every MCP session begins.
     *
     * @param \Closure(): void $toolsChanged Told when the server's tools change.
     * @param \Closure(string): void $closed Told why, once, when the server is gone.
     * @return array<string, mixed> What the server said of itself.
     */
    public function start(string $version, \Closure $toolsChanged, \Closure $closed, float $timeout, ?Cancellation $cancellation = null): array
    {
        $this->toolsChanged = $toolsChanged;
        $this->closed = $closed;
        $this->transport->open($this->receive(...), $this->lost(...));
        $answer = $this->request('initialize', [
            'protocolVersion' => self::VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'bia', 'version' => $version],
        ], $timeout, $cancellation);
        $this->notify('notifications/initialized');
        return is_array($answer) ? $answer : [];
    }

    /**
     * Every tool the server has, page after page.
     *
     * @return list<array<string, mixed>>
     */
    public function tools(float $timeout, ?Cancellation $cancellation = null): array
    {
        $tools = [];
        $cursor = null;
        do {
            $page = $this->request('tools/list', $cursor === null ? new \stdClass() : ['cursor' => $cursor], $timeout, $cancellation);
            foreach ((array) ($page['tools'] ?? []) as $tool) {
                if (is_array($tool) && is_string($tool['name'] ?? null)) {
                    $tools[] = $tool;
                }
            }
            $cursor = is_string($page['nextCursor'] ?? null) && $page['nextCursor'] !== '' ? $page['nextCursor'] : null;
        } while ($cursor !== null && count($tools) < 1000);
        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $tool, array $arguments, float $timeout, ?Cancellation $cancellation = null): array
    {
        $result = $this->request('tools/call', ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments], $timeout, $cancellation);
        return is_array($result) ? $result : [];
    }

    public function close(): void
    {
        $this->transport->close();
        $this->lost('closed');
    }

    /**
     * Sends a request and waits for its answer, no longer than the timeout.
     *
     * @param array<string, mixed>|\stdClass $params
     */
    public function request(string $method, array|\stdClass $params, float $timeout, ?Cancellation $cancellation = null): mixed
    {
        if ($this->gone !== null) {
            throw new \RuntimeException("the server is gone: {$this->gone}");
        }
        $cancellation?->throwIfCancelled();
        $id = $this->next++;
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $this->waiting[$id] = $suspension;
        $timer = $loop->delay($timeout, static fn () => $suspension->throw(new \RuntimeException("no answer to {$method} within " . (int) $timeout . ' s')));
        $unsubscribe = $cancellation?->subscribe(function (\Throwable $error) use ($suspension, $id): void {
            $this->notify('notifications/cancelled', ['requestId' => $id, 'reason' => 'cancelled by the user']);
            $suspension->throw($error);
        });
        try {
            $this->transport->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
            $message = $suspension->suspend();
        } finally {
            unset($this->waiting[$id]);
            $loop->cancel($timer);
            if ($unsubscribe !== null) {
                $unsubscribe();
            }
        }
        if (isset($message['error'])) {
            $error = $message['error'];
            throw new \RuntimeException(is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'the server answered with an error');
        }
        return $message['result'] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function notify(string $method, array $params = []): void
    {
        if ($this->gone === null) {
            $this->transport->send(['jsonrpc' => '2.0', 'method' => $method] + ($params === [] ? [] : ['params' => $params]));
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function receive(array $message): void
    {
        $method = $message['method'] ?? null;
        if ($method === null && isset($message['id']) && is_int($message['id'])) {
            ($this->waiting[$message['id']] ?? null)?->resume($message); // an answer to one of ours; a late one is dropped
            return;
        }
        if (!is_string($method)) {
            return;
        }
        if (isset($message['id'])) {
            // The server asks something of Bia: a ping is answered, the rest is not offered.
            $this->transport->send($method === 'ping'
                ? ['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => new \stdClass()]
                : ['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32601, 'message' => "Bia does not offer {$method}"]]);
            return;
        }
        if ($method === 'notifications/tools/list_changed' && $this->toolsChanged !== null) {
            ($this->toolsChanged)();
        }
    }

    private function lost(string $why): void
    {
        if ($this->gone !== null) {
            return;
        }
        $this->gone = $why;
        foreach ($this->waiting as $suspension) {
            $suspension->throw(new \RuntimeException("the server is gone: {$why}"));
        }
        $this->waiting = [];
        if ($this->closed !== null) {
            ($this->closed)($why);
        }
    }
}

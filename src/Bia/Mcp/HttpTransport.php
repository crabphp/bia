<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;

/**
 * A server reached over MCP's streamable HTTP: each message is posted, and
 * the answer comes back as JSON, or as a stream of events carrying one or
 * more messages. The session the server gives on starting goes with every
 * message after.
 */
final class HttpTransport implements Transport
{
    private ?string $session = null;
    private ?\Closure $receive = null;
    private ?\Closure $closed = null;
    private bool $open = false;
    private bool $needsLogin = false;

    public function __construct(
        private readonly Server $server,
        private readonly Client $http,
        private readonly float $timeout = 120.0,
        private readonly ?OAuth $auth = null,
    ) {}

    /**
     * Whether the server refused Bia for want of a login, one its tokens could not renew.
     */
    public function needsLogin(): bool
    {
        return $this->needsLogin;
    }

    public function open(\Closure $receive, \Closure $closed): void
    {
        $this->receive = $receive;
        $this->closed = $closed;
        $this->open = true;
    }

    public function send(array $message): void
    {
        if (!$this->open) {
            return;
        }
        // Each post in a task of its own: an answer streamed slowly does not hold the next message up.
        Task::spawn(EventLoop::current(), function () use ($message): void {
            try {
                $this->post($message);
            } catch (\Throwable $error) {
                if (isset($message['id'])) {
                    ($this->receive)(['jsonrpc' => '2.0', 'id' => $message['id'], 'error' => ['code' => -32000, 'message' => $error->getMessage()]]);
                }
            }
        });
    }

    public function close(): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        if ($this->session !== null) {
            // Ends the session on the server; whether it hears is not waited for.
            $session = $this->session;
            Task::spawn(EventLoop::current(), function () use ($session): void {
                try {
                    $this->http->timeout(5.0)->request('DELETE', $this->server->url, headers: [...$this->server->headers, 'Mcp-Session-Id' => $session]);
                } catch (\Throwable) {
                }
            });
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function post(array $message, bool $renewed = false): void
    {
        $headers = [...$this->server->headers, 'Accept' => 'application/json, text/event-stream'];
        if ($this->session !== null) {
            $headers['Mcp-Session-Id'] = $this->session;
        }
        if (($message['method'] ?? '') !== 'initialize') {
            $headers['MCP-Protocol-Version'] = Connection::VERSION;
        }
        // A token from logging in, unless the config gives its own Authorization.
        $configured = array_change_key_case($this->server->headers);
        if ($this->auth !== null && !isset($configured['authorization']) && ($bearer = $this->auth->header()) !== null) {
            $headers['Authorization'] = $bearer;
        }
        $response = $this->http->timeout($this->timeout)->stream('POST', $this->server->url, headers: $headers, json: $message, idle: $this->timeout);
        if ($response->getStatusCode() === 401 && $this->auth !== null && !isset($configured['authorization'])) {
            $this->auth->challenged($response->getHeaderLine('www-authenticate'));
            if (!$renewed && $this->auth->refresh()) {
                $this->post($message, renewed: true); // a token that just ran out, renewed
                return;
            }
            $this->needsLogin = true;
            $this->open = false;
            ($this->closed)('needs login');
            return;
        }
        $session = $response->getHeaderLine('mcp-session-id');
        if ($session !== '') {
            $this->session = $session;
        }
        $status = $response->getStatusCode();
        if ($status === 404 && $this->session !== null) {
            $this->open = false;
            ($this->closed)('the server ended the session');
            return;
        }
        if ($status >= 400) {
            $body = trim(mb_strcut((string) $response->getBody(), 0, 300, 'UTF-8'));
            throw new \RuntimeException("HTTP {$status}" . ($body !== '' ? ": {$body}" : ''));
        }
        if ($status === 202 || !isset($message['id'])) {
            return; // a notification, or an answer to the server: nothing comes back
        }
        if (str_contains(strtolower($response->getHeaderLine('content-type')), 'text/event-stream')) {
            foreach ($response->events() as $event) {
                $this->deliver(json_decode($event->data, true));
            }
            return;
        }
        $this->deliver($response->json());
    }

    private function deliver(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }
        foreach (array_is_list($data) ? $data : [$data] as $one) {
            if (is_array($one)) {
                ($this->receive)($one);
            }
        }
    }
}

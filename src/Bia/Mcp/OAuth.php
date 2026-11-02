<?php

declare(strict_types=1);

namespace Bia\Mcp;

use Crab\Contracts\HttpClient\Client;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;

/**
 * Logging in to a remote MCP server, as MCP's authorization asks: the
 * server says where its authorization server is (RFC 9728); Bia reads that
 * server's metadata (RFC 8414), registers itself when it may (RFC 7591),
 * and has the user log in in their browser with PKCE, the code coming back
 * to a listener on 127.0.0.1, or pasted. Tokens are bound to the MCP
 * server (RFC 8707), kept in the AuthStore, and refreshed before they run out.
 */
final class OAuth
{
    /** Seconds before a token's end at which it is refreshed. */
    private const EARLY = 60;

    /** Seconds the user has to log in. */
    private const LOGIN_TIME = 300.0;

    /** @var array{prefix: string, state: string, deliver: \Closure(array<string, string>): void}|null While a login waits for its code. */
    private ?array $waiting = null;

    public function __construct(
        private readonly Server $server,
        private readonly Client $http,
        private readonly AuthStore $store,
        private readonly float $timeout = 30.0,
    ) {}

    /**
     * The Authorization header to send, refreshing the token first when it
     * is about to run out; null when there is no token to send.
     */
    public function header(): ?string
    {
        $tokens = $this->entry()['tokens'] ?? null;
        if (!is_array($tokens) || !is_string($tokens['access_token'] ?? null)) {
            return null;
        }
        $expires = (int) ($tokens['expires_at'] ?? 0);
        if ($expires > 0 && $expires - self::EARLY <= time()) {
            if (!$this->refresh()) {
                return null;
            }
            $tokens = $this->entry()['tokens'];
        }
        return 'Bearer ' . $tokens['access_token'];
    }

    /**
     * The server refused: it may have said where its metadata is, kept for the login.
     */
    public function challenged(string $wwwAuthenticate): void
    {
        if (preg_match('/resource_metadata="([^"]+)"/', $wwwAuthenticate, $m) === 1) {
            $entry = $this->entry();
            $entry['resource_metadata'] = $m[1];
            $this->store->put($this->key(), $entry);
        }
    }

    /**
     * Gets a new access token with the refresh token; false when there is
     * none or it was refused, and a login is needed.
     */
    public function refresh(): bool
    {
        $entry = $this->entry();
        $refresh = $entry['tokens']['refresh_token'] ?? null;
        if (!is_string($refresh) || !is_array($entry['client'] ?? null) || !is_string($entry['token_endpoint'] ?? null)) {
            return false;
        }
        try {
            $tokens = $this->token($entry['token_endpoint'], $entry['client'], ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (\Throwable) {
            return false;
        }
        $tokens['refresh_token'] ??= $refresh; // a server may keep the same one
        $entry['tokens'] = $tokens;
        $this->store->put($this->key(), $entry);
        return true;
    }

    public function loggedIn(): bool
    {
        return is_string($this->entry()['tokens']['access_token'] ?? null);
    }

    public function forget(): void
    {
        $this->store->forget($this->key());
    }

    /**
     * The address a pasted redirect starts with, while a login waits for it.
     */
    public function waiting(): ?string
    {
        return $this->waiting['prefix'] ?? null;
    }

    /**
     * The address the browser was sent back to, pasted by the user, as when
     * Bia runs over SSH and the browser is elsewhere; false when it is not
     * this login's.
     */
    public function deliver(string $url): bool
    {
        if ($this->waiting === null || !str_starts_with($url, $this->waiting['prefix'])) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        ($this->waiting['deliver'])(array_map('strval', array_filter($query, 'is_scalar')));
        return true;
    }

    /**
     * Logs in: finds the authorization server, registers when it must,
     * sends the user to log in, and keeps the tokens.
     *
     * @param \Closure(string): void $open Given the address the user logs in at: to open a browser, and to show it.
     */
    public function login(\Closure $open, ?Cancellation $cancellation = null): void
    {
        $entry = $this->entry();
        [$resource, $metadata] = $this->discover($entry['resource_metadata'] ?? null);
        if (!in_array('S256', (array) ($metadata['code_challenge_methods_supported'] ?? ['S256']), true)) {
            throw new \RuntimeException('the authorization server does not support PKCE with S256, which MCP requires');
        }
        $listener = $this->listen((int) ($this->server->oauth['callbackPort'] ?? $entry['client']['port'] ?? 0));
        try {
            $redirect = 'http://127.0.0.1:' . $listener['port'] . '/callback';
            $client = $this->client($metadata, $redirect, $entry);
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $state = bin2hex(random_bytes(16));
            $scopes = $this->server->oauth['scopes'] ?? $resource['scopes_supported'] ?? null;
            $query = array_filter([
                'response_type' => 'code',
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirect,
                'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'state' => $state,
                'scope' => is_array($scopes) ? implode(' ', array_map('strval', $scopes)) : (is_string($scopes) ? $scopes : null),
                'resource' => $this->server->url,
            ], static fn ($value): bool => $value !== null && $value !== '');
            $endpoint = (string) $metadata['authorization_endpoint'];
            $address = $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

            $code = $this->await($listener, $redirect, $state, $address, $open, $cancellation);
            $tokens = $this->token((string) $metadata['token_endpoint'], $client, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect,
                'code_verifier' => $verifier,
            ]);
        } finally {
            $this->waiting = null;
            ($listener['close'])();
        }
        $entry = $this->entry();
        $entry['client'] = $client;
        $entry['token_endpoint'] = (string) $metadata['token_endpoint'];
        $entry['tokens'] = $tokens;
        $this->store->put($this->key(), $entry);
    }

    /**
     * The protected resource's metadata and its authorization server's: from
     * where the server said, else the well-known addresses; for a server that
     * has neither, its own origin, at the default paths.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function discover(?string $resourceMetadata): array
    {
        $url = parse_url($this->server->url);
        $origin = ($url['scheme'] ?? 'https') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '');
        $path = rtrim((string) ($url['path'] ?? ''), '/');
        $resource = [];
        foreach (array_filter([$resourceMetadata, $path !== '' ? "{$origin}/.well-known/oauth-protected-resource{$path}" : null, "{$origin}/.well-known/oauth-protected-resource"]) as $where) {
            if (($found = $this->getJson($where)) !== null) {
                $resource = $found;
                break;
            }
        }
        $issuer = is_string($resource['authorization_servers'][0] ?? null) ? rtrim($resource['authorization_servers'][0], '/') : $origin;
        $issuerUrl = parse_url($issuer);
        $issuerOrigin = ($issuerUrl['scheme'] ?? 'https') . '://' . ($issuerUrl['host'] ?? '') . (isset($issuerUrl['port']) ? ':' . $issuerUrl['port'] : '');
        $issuerPath = rtrim((string) ($issuerUrl['path'] ?? ''), '/');
        foreach (array_unique(array_filter([
            "{$issuerOrigin}/.well-known/oauth-authorization-server{$issuerPath}",
            "{$issuerOrigin}/.well-known/openid-configuration{$issuerPath}",
            $issuerPath !== '' ? "{$issuer}/.well-known/openid-configuration" : null,
        ])) as $where) {
            $metadata = $this->getJson($where);
            if (is_string($metadata['authorization_endpoint'] ?? null) && is_string($metadata['token_endpoint'] ?? null)) {
                return [$resource, $metadata];
            }
        }
        // An older server with no metadata at all: the defaults MCP named for it.
        return [$resource, [
            'authorization_endpoint' => "{$issuer}/authorize",
            'token_endpoint' => "{$issuer}/token",
            'registration_endpoint' => "{$issuer}/register",
        ]];
    }

    /**
     * The client to log in as: one set in mcp.json; else the one registered
     * before, while it is for this redirect; else a new one, registered now.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function client(array $metadata, string $redirect, array $entry): array
    {
        if (is_string($this->server->oauth['clientId'] ?? null)) {
            return array_filter([
                'client_id' => $this->server->oauth['clientId'],
                'client_secret' => $this->server->oauth['clientSecret'] ?? null,
                'token_endpoint_auth_method' => isset($this->server->oauth['clientSecret']) ? 'client_secret_post' : 'none',
            ]);
        }
        $known = $entry['client'] ?? null;
        if (is_array($known) && ($known['redirect_uri'] ?? null) === $redirect && is_string($known['client_id'] ?? null)) {
            return $known;
        }
        $endpoint = $metadata['registration_endpoint'] ?? null;
        if (!is_string($endpoint)) {
            throw new \RuntimeException('the server needs a client id: set oauth.clientId for it in mcp.json');
        }
        $response = $this->http->timeout($this->timeout)->request('POST', $endpoint, headers: ['Accept' => 'application/json'], json: [
            'client_name' => 'Bia',
            'redirect_uris' => [$redirect],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]);
        $client = $response->json();
        if (!$response->ok() || !is_array($client) || !is_string($client['client_id'] ?? null)) {
            throw new \RuntimeException('registering with the authorization server failed (HTTP ' . $response->getStatusCode() . ')');
        }
        $client['redirect_uri'] = $redirect;
        $client['port'] = (int) parse_url($redirect, PHP_URL_PORT);
        return $client;
    }

    /**
     * Asks the token endpoint, as the client was registered to authenticate.
     *
     * @param array<string, mixed> $client
     * @param array<string, string> $form
     * @return array<string, mixed> The tokens, with when the access token runs out.
     */
    private function token(string $endpoint, array $client, array $form): array
    {
        $form += ['client_id' => (string) $client['client_id'], 'resource' => $this->server->url];
        $headers = ['Accept' => 'application/json'];
        $secret = $client['client_secret'] ?? null;
        if (is_string($secret) && $secret !== '') {
            if (($client['token_endpoint_auth_method'] ?? 'client_secret_basic') === 'client_secret_post') {
                $form['client_secret'] = $secret;
            } else {
                $headers['Authorization'] = 'Basic ' . base64_encode(rawurlencode((string) $client['client_id']) . ':' . rawurlencode($secret));
            }
        }
        $response = $this->http->timeout($this->timeout)->request('POST', $endpoint, headers: $headers, form: $form);
        $tokens = $response->json();
        if (!is_array($tokens)) {
            parse_str((string) $response->getBody(), $tokens); // some answer as a form
        }
        if (!$response->ok() || !is_string($tokens['access_token'] ?? null)) {
            $why = is_string($tokens['error_description'] ?? null) ? $tokens['error_description'] : (is_string($tokens['error'] ?? null) ? $tokens['error'] : 'HTTP ' . $response->getStatusCode());
            throw new \RuntimeException("the token request failed: {$why}");
        }
        if (isset($tokens['expires_in']) && is_numeric($tokens['expires_in'])) {
            $tokens['expires_at'] = time() + (int) $tokens['expires_in'];
        }
        return $tokens;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $url): ?array
    {
        try {
            $response = $this->http->timeout($this->timeout)->request('GET', $url, headers: ['Accept' => 'application/json', 'MCP-Protocol-Version' => Connection::VERSION]);
            $data = $response->ok() ? $response->json() : null;
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A listener on 127.0.0.1 for the browser's redirect: on the port given
     * when it is free, as a client registered for it needs, else on any.
     *
     * @return array{port: int, socket: resource, close: \Closure(): void}
     */
    private function listen(int $port): array
    {
        $socket = $port > 0 ? @stream_socket_server("tcp://127.0.0.1:{$port}") : false;
        if ($socket === false) {
            $socket = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        }
        if ($socket === false) {
            throw new \RuntimeException('could not listen on 127.0.0.1 for the login: ' . ($message ?? 'unknown error'));
        }
        stream_set_blocking($socket, false);
        $name = (string) stream_socket_get_name($socket, false);
        return [
            'port' => (int) substr($name, strrpos($name, ':') + 1),
            'socket' => $socket,
            'close' => static function () use ($socket): void {
                if (is_resource($socket)) {
                    @fclose($socket);
                }
            },
        ];
    }

    /**
     * Sends the user to log in, and waits for the code: from the browser's
     * redirect to the listener, or from a pasted address.
     *
     * @param array{port: int, socket: resource, close: \Closure(): void} $listener
     * @param \Closure(string): void $open
     */
    private function await(array $listener, string $redirect, string $state, string $address, \Closure $open, ?Cancellation $cancellation): string
    {
        $loop = EventLoop::current();
        $suspension = $loop->getSuspension();
        $settled = false;
        $finish = static function (array $query) use ($suspension, &$settled): void {
            if (!$settled) {
                $settled = true;
                $suspension->resume($query);
            }
        };
        $this->waiting = ['prefix' => $redirect, 'state' => $state, 'deliver' => $finish];
        $readers = [];
        $watcher = $loop->onReadable($listener['socket'], static function () use ($listener, $finish, $loop, &$readers): void {
            $connection = @stream_socket_accept($listener['socket'], 0);
            if ($connection === false) {
                return;
            }
            // Read as it comes, on the loop: the browser may be slow, and Bia keeps running meanwhile.
            stream_set_blocking($connection, false);
            $request = '';
            $id = (int) $connection;
            $readers[$id] = $loop->onReadable($connection, static function () use ($connection, $finish, $loop, &$readers, $id, &$request): void {
                $chunk = @fread($connection, 8192);
                $request .= (string) $chunk;
                $ended = $chunk === false || ($chunk === '' && feof($connection));
                if (!str_contains($request, "\r\n") && !$ended && strlen($request) < 8192) {
                    return; // the request line is not all here yet
                }
                $loop->cancel($readers[$id]);
                unset($readers[$id]);
                $target = preg_match('#^GET (\S+) HTTP/#', $request, $m) === 1 ? $m[1] : '';
                parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
                $mine = str_starts_with($target, '/callback') && (isset($query['code']) || isset($query['error']));
                $page = $mine
                    ? '<!doctype html><meta charset="utf-8"><title>Bia</title><p style="font:16px system-ui;margin:3em">Logged in. You can close this tab and go back to Bia.</p>'
                    : 'Not found';
                @fwrite($connection, ($mine ? "HTTP/1.1 200 OK\r\n" : "HTTP/1.1 404 Not Found\r\n") . "Content-Type: text/html; charset=utf-8\r\nContent-Length: " . strlen($page) . "\r\nConnection: close\r\n\r\n" . $page);
                @fclose($connection);
                if ($mine) {
                    $finish(array_map('strval', array_filter($query, 'is_scalar')));
                }
            });
        });
        $timer = $loop->delay(self::LOGIN_TIME, static fn () => $suspension->throw(new \RuntimeException('the login was not finished within ' . (int) (self::LOGIN_TIME / 60) . ' minutes')));
        $unsubscribe = $cancellation?->subscribe(static fn (\Throwable $error) => $suspension->throw($error));
        try {
            $open($address);
            $query = $suspension->suspend();
        } finally {
            $loop->cancel($watcher);
            foreach ($readers as $reader) {
                $loop->cancel($reader); // a connection still sending gives up with the listener
            }
            $loop->cancel($timer);
            if ($unsubscribe !== null) {
                $unsubscribe();
            }
        }
        if (isset($query['error'])) {
            throw new \RuntimeException('the login was refused: ' . ($query['error_description'] ?? $query['error']));
        }
        if (($query['state'] ?? null) !== $state) {
            throw new \RuntimeException('the login came back with the wrong state; it may not have been yours, so it was not used');
        }
        return (string) ($query['code'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        return $this->store->get($this->key());
    }

    private function key(): string
    {
        return AuthStore::key($this->server);
    }
}

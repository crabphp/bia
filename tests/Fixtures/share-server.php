<?php

declare(strict_types=1);

// Scripted relay and OpenAI-compatible server. Loopback only; never a production relay.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\Connection;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\WebSocketSession;

function shareFixture(EventLoop $loop): array
{
    $clients = [];
    $connections = [];
    $seen = [];
    $dropTo = '';
    $forge = false;
    $relay = new WebSocketServer(function (WebSocketSession $socket, string $wire) use (&$clients, &$seen, &$dropTo, &$forge): void {
        $m = json_decode($wire, true);
        if (($m[0] ?? '') === 'REQ') {
            $clients[spl_object_id($socket)] = [$socket, $m[1], $m[2]];
            $socket->sendText(json_encode(['EOSE', $m[1]]));
        } elseif (($m[0] ?? '') === 'EVENT') {
            $e = $m[1];
            $socket->sendText(json_encode(['OK', $e['id'], true, '']));
            // Like a relay, deduplicate publication ids, and store no ephemeral history.
            if (isset($seen[$e['id']])) { return; }
            $seen[$e['id']] = true;
            if (count($seen) > 1024) { array_shift($seen); }
            if (($e['tags'][0][1] ?? '') === $dropTo) { return; }
            foreach ($clients as [$peer, $subscription, $filter]) {
                if (!$peer->isClosed() && in_array($e['kind'], $filter['kinds'] ?? [], true)
                    && in_array($e['tags'][0][1] ?? '', $filter['#p'] ?? [], true)) {
                    if ($forge && $e['kind'] === \Bia\Share\Discovery::KIND) {
                        $peer->sendText(json_encode(['EVENT', $subscription, ['sig' => str_repeat('0', 128)] + $e]));
                    }
                    $peer->sendText(json_encode(['EVENT', $subscription, $e]));
                }
            }
        }
    }, onClose: static function (WebSocketSession $socket) use (&$clients): void { unset($clients[spl_object_id($socket)]); });
    $model = new HttpServer(static function (): Response {
        $body = '';
        foreach (['Remote ', 'debugging ', 'works.'] as $text) {
            $body .= 'data: ' . json_encode(['choices' => [['delta' => ['content' => $text], 'index' => 0]]]) . "\n\n";
        }
        $body .= 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop', 'index' => 0]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3]]) . "\n\ndata: [DONE]\n\n";
        return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
    });
    $listeners = [];
    $watchers = [];
    $addresses = [];
    foreach ([$relay, $model] as $server) {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        stream_set_blocking($listener, false);
        $listeners[] = $listener;
        $addresses[] = stream_socket_get_name($listener, false);
        $watchers[] = $loop->onReadable($listener, static function () use ($loop, $listener, $server, &$connections): void {
            while (($stream = @stream_socket_accept($listener, 0)) !== false) {
                $connection = new Connection($loop, $stream);
                $id = spl_object_id($connection);
                $connections[$id] = $connection;
                $connection->onClose(static function () use (&$connections, $id): void { unset($connections[$id]); });
                $server->attach($connection);
            }
        });
    }
    return ['relay' => 'ws://' . $addresses[0], 'model' => 'http://' . $addresses[1] . '/v1',
        'drop' => static function (string $recipient) use (&$dropTo): void { $dropTo = $recipient; },
        'forge' => static function () use (&$forge): void { $forge = true; },
        'clients' => static function () use (&$clients): int { return count(array_filter($clients, static fn (array $client): bool => !$client[0]->isClosed())); },
        'disconnect' => static function () use (&$connections): void { foreach ($connections as $connection) { $connection->close(); } },
        'close' => static function () use ($loop, $listeners, $watchers, &$connections): void {
            foreach ($watchers as $watcher) { $loop->cancel($watcher); }
            foreach ($connections as $connection) { $connection->close(); }
            foreach ($listeners as $listener) { fclose($listener); }
        }];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $loop = EventLoop::install(new StreamSelectEventLoop());
    $fixture = shareFixture($loop);
    echo json_encode(['relay' => $fixture['relay'], 'model' => $fixture['model']]), "\n";
    $loop->run();
}

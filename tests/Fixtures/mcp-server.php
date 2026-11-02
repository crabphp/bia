<?php

declare(strict_types=1);

// A small MCP server over standard input and output, for the tests: two
// pages of tools, one that echoes, one that fails, one that takes its time.

$reply = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
};
fwrite(STDERR, "fixture server starting\n");
fwrite(STDOUT, "this line is not JSON and is to be ignored\n");

while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true);
    if (!is_array($message) || !isset($message['method'])) {
        continue;
    }
    $id = $message['id'] ?? null;
    $params = $message['params'] ?? [];
    switch ($message['method']) {
        case 'initialize':
            $reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'protocolVersion' => $params['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => ['listChanged' => true]],
                'serverInfo' => ['name' => 'fixture', 'version' => '1.0'],
            ]]);
            // The server pings the client once, as servers may.
            $reply(['jsonrpc' => '2.0', 'id' => 'ping-1', 'method' => 'ping']);
            break;
        case 'tools/list':
            $second = ($params['cursor'] ?? null) === 'page-2';
            $reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => $second
                ? ['tools' => [
                    ['name' => 'fail', 'description' => 'Always fails.', 'inputSchema' => ['type' => 'object']],
                    ['name' => 'slow', 'description' => 'Takes a minute.', 'inputSchema' => ['type' => 'object']],
                ]]
                : ['tools' => [
                    ['name' => 'echo', 'description' => 'Says it back.', 'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]],
                ], 'nextCursor' => 'page-2']]);
            break;
        case 'tools/call':
            $name = $params['name'] ?? '';
            if ($name === 'echo') {
                $reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [
                    ['type' => 'text', 'text' => 'echo: ' . ($params['arguments']['text'] ?? '')],
                    ['type' => 'image', 'data' => 'AAAA', 'mimeType' => 'image/png'],
                ]]]);
            } elseif ($name === 'fail') {
                $reply(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => 'it went wrong']], 'isError' => true]]);
            } elseif ($name === 'slow') {
                sleep(60);
            } else {
                $reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => "no tool {$name}"]]);
            }
            break;
        default:
            if ($id !== null && !isset($message['result'])) {
                $reply(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'unknown method']]);
            }
    }
}

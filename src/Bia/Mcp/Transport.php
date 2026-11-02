<?php

declare(strict_types=1);

namespace Bia\Mcp;

/**
 * How messages go to an MCP server and come back: each a JSON-RPC message,
 * as an array.
 */
interface Transport
{
    /**
     * Opens the way to the server.
     *
     * @param \Closure(array<string, mixed>): void $receive Given each message the server sends.
     * @param \Closure(string): void $closed Told why, once, when the server is gone.
     */
    public function open(\Closure $receive, \Closure $closed): void;

    /**
     * @param array<string, mixed> $message
     */
    public function send(array $message): void;

    public function close(): void;
}

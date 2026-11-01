<?php

declare(strict_types=1);

namespace Bia\Tests;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\ToolCall;
use Bia\Session\SqliteStore;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    public function testEntriesAreDurableAndOrdered(): void
    {
        $path = $this->path();
        $store = new SqliteStore($path);
        $id = $store->create('/workspace', 'test-model');
        $store->append($id, Message::user('hello'));
        $store->append($id, new Message('assistant', 'hi', [new ToolCall('c1', 'read_file', ['path' => 'a'])], meta: ['finish_reason' => 'stop']));
        $store->append($id, new Compaction('summary', 1, 100, 10));
        $store->close();

        $path = (new SqliteStore($path))->path($id);

        self::assertCount(3, $path);
        self::assertSame('hello', $path[0]->message()?->text);
        self::assertSame('read_file', $path[1]->message()?->toolCalls[0]->name);
        self::assertSame(['finish_reason' => 'stop'], $path[1]->message()?->meta);
        self::assertInstanceOf(Compaction::class, $path[2]->payload);
        self::assertSame([null, $path[0]->id, $path[1]->id], array_map(static fn (Entry $e): ?int => $e->parentId, $path));
    }

    public function testAForkSharesHistoryAndThenGoesItsOwnWay(): void
    {
        $store = new SqliteStore($this->path());
        $original = $store->create('/workspace', 'm');
        $store->append($original, Message::user('shared'));
        $fork = $store->create('/workspace', 'm', fromEntry: $store->head($original));

        $store->append($fork, Message::user('only in fork'));
        $store->append($original, Message::user('only in original'));

        $texts = static fn (string $id): array => array_map(static fn (Entry $e): string => (string) $e->message()?->text, $store->path($id));
        self::assertSame(['shared', 'only in fork'], $texts($fork));
        self::assertSame(['shared', 'only in original'], $texts($original));
    }

    public function testTheFirstSchemaIsMigrated(): void
    {
        $path = $this->path();
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY, workspace TEXT NOT NULL, model TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE, sequence INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, metadata TEXT NOT NULL, created_at TEXT NOT NULL)');
        $pdo->exec("INSERT INTO sessions VALUES ('old', '/w', 'm', 'now', 'now')");
        $insert = $pdo->prepare('INSERT INTO messages (session_id, sequence, role, content, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute(['old', 1, 'user', '"hi"', '[]', 'now']);
        $insert->execute(['old', 2, 'assistant', '""', json_encode(['tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'git_diff', 'arguments' => '{}']]]]), 'now']);
        $insert->execute(['old', 3, 'tool', '"diff"', json_encode(['tool_call_id' => 'c1', 'name' => 'git_diff', 'ok' => true]), 'now']);
        $pdo = null;

        $path = (new SqliteStore($path))->path('old');

        self::assertSame(['user', 'assistant', 'tool'], array_map(static fn (Entry $e): string => (string) $e->message()?->role, $path));
        self::assertSame('git_diff', $path[1]->message()?->toolCalls[0]->name);
        self::assertSame('c1', $path[2]->message()?->toolCallId);
    }

    public function testSessionsAreListedWithTitlesAndFoundByIdPrefix(): void
    {
        $store = new SqliteStore(sys_get_temp_dir() . '/bia-list-' . bin2hex(random_bytes(4)) . '/bia.sqlite');
        $here = $store->create('/work/here', 'tda/astra', 'abc123');
        $store->append($here, \Bia\Contracts\Message::user('fix the failing test'));
        $store->append($here, new \Bia\Contracts\Message('assistant', 'done'));
        $store->create('/work/other', 'tda/astra', 'abd456');

        $listed = $store->list(workspace: '/work/here');
        self::assertCount(1, $listed);
        self::assertSame('fix the failing test', $listed[0]['title']);
        self::assertSame(2, $listed[0]['entry_count']);
        self::assertCount(2, $store->list());

        self::assertSame('abc123', $store->resolve('abc'));
        self::assertSame('abc123', $store->resolve('abc123'));
        self::assertNull($store->resolve('ab'), 'Two sessions start with it.');
        self::assertNull($store->resolve('a%'), 'A LIKE wildcard is taken literally.');
        self::assertNull($store->resolve(''));
    }

    private function path(): string
    {
        return sys_get_temp_dir() . '/bia-session-' . bin2hex(random_bytes(4)) . '.sqlite';
    }
}

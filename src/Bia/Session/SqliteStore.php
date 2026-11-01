<?php

declare(strict_types=1);

namespace Bia\Session;

use Bia\Contracts\Compaction;
use Bia\Contracts\Entry;
use Bia\Contracts\Message;
use Bia\Contracts\SessionStore;
use Bia\Contracts\ToolCall;

/**
 * The local SQLite conversation store: an append-only log of entries, each
 * pointing at its parent, and a head per session. It opens and migrates
 * lazily, so commands such as `bia --help` never touch the database.
 */
final class SqliteStore implements SessionStore
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $path) {}

    public function create(string $workspace, string $model, ?string $id = null, ?int $fromEntry = null): string
    {
        $pdo = $this->open();
        $id ??= self::id();
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO sessions (id, workspace, model, head, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
        )->execute([$id, $workspace, $model, $fromEntry, $now, $now]);
        return $id;
    }

    public function append(string $sessionId, Message|Compaction $payload): Entry
    {
        $pdo = $this->open();
        $pdo->beginTransaction();
        try {
            $head = $pdo->prepare('SELECT head FROM sessions WHERE id = ?');
            $head->execute([$sessionId]);
            $row = $head->fetch();
            if ($row === false) {
                throw new \RuntimeException("Session {$sessionId} does not exist.");
            }
            $parent = $row['head'] === null ? null : (int) $row['head'];
            $now = gmdate('c');
            $pdo->prepare(
                'INSERT INTO entries (session_id, parent_id, type, data, created_at) VALUES (?, ?, ?, ?, ?)',
            )->execute([
                $sessionId,
                $parent,
                $payload instanceof Message ? 'message' : 'compaction',
                json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $now,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE sessions SET head = ?, updated_at = ? WHERE id = ?')->execute([$id, $now, $sessionId]);
            $pdo->commit();
            return new Entry($id, $parent, $payload, $now);
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    public function path(string $sessionId): array
    {
        $pdo = $this->open();
        // Walk from the head to the root; a forked session's path runs into the session it came from.
        $statement = $pdo->prepare(<<<'SQL'
            WITH RECURSIVE path(id, parent_id, type, data, created_at, depth) AS (
                SELECT e.id, e.parent_id, e.type, e.data, e.created_at, 0
                FROM entries e JOIN sessions s ON e.id = s.head WHERE s.id = ?
                UNION ALL
                SELECT e.id, e.parent_id, e.type, e.data, e.created_at, path.depth + 1
                FROM entries e JOIN path ON e.id = path.parent_id
            )
            SELECT id, parent_id, type, data, created_at FROM path ORDER BY depth DESC
            SQL);
        $statement->execute([$sessionId]);
        $entries = [];
        while ($row = $statement->fetch()) {
            $data = json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException("Session {$sessionId} has an unreadable entry {$row['id']}.");
            }
            $entries[] = new Entry(
                (int) $row['id'],
                $row['parent_id'] === null ? null : (int) $row['parent_id'],
                $row['type'] === 'compaction' ? Compaction::fromArray($data) : Message::fromArray($data),
                (string) $row['created_at'],
            );
        }
        return $entries;
    }

    public function head(string $sessionId): ?int
    {
        $statement = $this->open()->prepare('SELECT head FROM sessions WHERE id = ?');
        $statement->execute([$sessionId]);
        $head = $statement->fetchColumn();
        return $head === false || $head === null ? null : (int) $head;
    }

    public function exists(string $sessionId): bool
    {
        $statement = $this->open()->prepare('SELECT 1 FROM sessions WHERE id = ? LIMIT 1');
        $statement->execute([$sessionId]);
        return $statement->fetchColumn() !== false;
    }

    public function list(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->open()->query(
            'SELECT s.id, s.workspace, s.model, s.created_at, s.updated_at, COUNT(e.id) AS entry_count '
            . 'FROM sessions s LEFT JOIN entries e ON e.session_id = s.id '
            . 'GROUP BY s.id ORDER BY s.updated_at DESC LIMIT ' . $limit,
        );
        return $statement->fetchAll();
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function open(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create Bia state directory {$directory}.");
        }
        $pdo = new \PDO('sqlite:' . $this->path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 2500');
        $this->migrate($pdo);
        return $this->pdo = $pdo;
    }

    private function migrate(\PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= 2) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $hadMessages = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'messages'")->fetchColumn();
            $hadSessions = (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sessions'")->fetchColumn();
            if ($hadSessions) {
                $pdo->exec('ALTER TABLE sessions RENAME TO sessions_v1');
            }
            $pdo->exec(<<<'SQL'
                CREATE TABLE sessions (
                    id TEXT PRIMARY KEY,
                    workspace TEXT NOT NULL,
                    model TEXT NOT NULL,
                    head INTEGER,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TABLE entries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_id TEXT NOT NULL,
                    parent_id INTEGER,
                    type TEXT NOT NULL,
                    data TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
                SQL);
            $pdo->exec('CREATE INDEX entries_session ON entries(session_id)');
            if ($hadSessions) {
                $pdo->exec('INSERT INTO sessions (id, workspace, model, head, created_at, updated_at) SELECT id, workspace, model, NULL, created_at, updated_at FROM sessions_v1');
                if ($hadMessages) {
                    $this->migrateMessages($pdo);
                    $pdo->exec('DROP TABLE messages');
                }
                $pdo->exec('DROP TABLE sessions_v1');
            }
            $pdo->exec('PRAGMA user_version = 2');
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Turns the first schema's OpenAI-shaped messages into entries.
     */
    private function migrateMessages(\PDO $pdo): void
    {
        $rows = $pdo->query('SELECT session_id, role, content, metadata, created_at FROM messages ORDER BY session_id, sequence');
        $insert = $pdo->prepare('INSERT INTO entries (session_id, parent_id, type, data, created_at) VALUES (?, ?, ?, ?, ?)');
        $head = $pdo->prepare('UPDATE sessions SET head = ? WHERE id = ?');
        $parents = [];
        foreach ($rows->fetchAll() as $row) {
            $content = json_decode((string) $row['content'], true);
            $metadata = json_decode((string) $row['metadata'], true) ?: [];
            $calls = [];
            foreach ($metadata['tool_calls'] ?? [] as $call) {
                $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $calls[] = new ToolCall((string) ($call['id'] ?? ''), (string) ($call['function']['name'] ?? ''), is_array($arguments) ? $arguments : []);
            }
            $message = new Message(
                (string) $row['role'],
                is_string($content) ? $content : json_encode($content),
                $calls,
                toolCallId: isset($metadata['tool_call_id']) ? (string) $metadata['tool_call_id'] : null,
                toolName: isset($metadata['name']) ? (string) $metadata['name'] : null,
                ok: (bool) ($metadata['ok'] ?? true),
                meta: array_filter(['usage' => $metadata['usage'] ?? null, 'finish_reason' => $metadata['finish_reason'] ?? null]),
            );
            $session = (string) $row['session_id'];
            $insert->execute([$session, $parents[$session] ?? null, 'message', json_encode($message->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $row['created_at']]);
            $parents[$session] = (int) $pdo->lastInsertId();
            $head->execute([$parents[$session], $session]);
        }
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(12));
    }
}

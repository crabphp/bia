<?php

declare(strict_types=1);

namespace Bia\Session;

use Bia\Contracts\Compaction;
use Bia\Contracts\Pruning;
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
    private ?\PDOStatement $recordStatement = null;

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

    public function append(string $sessionId, Message|Compaction|Pruning $payload): Entry
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
                match (true) {
                    $payload instanceof Message => 'message',
                    $payload instanceof Pruning => 'pruning',
                    default => 'compaction',
                },
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
                match ($row['type']) {
                    'compaction' => Compaction::fromArray($data),
                    'pruning' => Pruning::fromArray($data),
                    default => Message::fromArray($data),
                },
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

    public function list(int $limit = 50, ?string $workspace = null): array
    {
        $limit = max(1, min(500, $limit));
        // Walk from each session's head to the root, so a forked session counts
        // and titles the conversation it branched from.
        $where = $workspace !== null ? ' WHERE s.workspace = ?' : '';
        $statement = $this->open()->prepare(<<<SQL
            WITH RECURSIVE
            listed AS (
                SELECT id, workspace, model, head, created_at, updated_at
                FROM sessions s{$where} ORDER BY updated_at DESC LIMIT {$limit}
            ),
            path(session_id, id, parent_id, type, data, depth) AS (
                SELECT l.id, e.id, e.parent_id, e.type, e.data, 0
                FROM listed l JOIN entries e ON e.id = l.head
                UNION ALL
                SELECT path.session_id, e.id, e.parent_id, e.type, e.data, path.depth + 1
                FROM entries e JOIN path ON e.id = path.parent_id
            )
            SELECT l.id, l.workspace, l.model, l.created_at, l.updated_at,
                (SELECT COUNT(*) FROM path p WHERE p.session_id = l.id) AS entry_count,
                (SELECT json_extract(p.data, '$.text') FROM path p
                    WHERE p.session_id = l.id AND p.type = 'message' AND json_extract(p.data, '$.role') = 'user'
                    ORDER BY p.depth DESC LIMIT 1) AS title
            FROM listed l ORDER BY l.updated_at DESC
            SQL);
        $statement->execute($workspace !== null ? [$workspace] : []);
        return array_map(static function (array $row): array {
            $row['entry_count'] = (int) $row['entry_count'];
            return $row;
        }, $statement->fetchAll());
    }

    public function resolve(string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        if ($this->exists($id)) {
            return $id;
        }
        $statement = $this->open()->prepare("SELECT id FROM sessions WHERE id LIKE ? ESCAPE '\\' LIMIT 2");
        $statement->execute([addcslashes($id, '%_\\') . '%']);
        $found = $statement->fetchAll(\PDO::FETCH_COLUMN);
        return count($found) === 1 ? (string) $found[0] : null;
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
        $this->migrateRequests($pdo);
        $pdo->prepare('DELETE FROM requests WHERE at < ?')->execute([time() - self::USAGE_DAYS * 86400]);
        return $this->pdo = $pdo;
    }

    /** Days of usage kept; older rows go as the store opens. */
    public const USAGE_DAYS = 90;

    /**
     * Version 3: a row for each answer from a model, for usage. The replies
     * kept before it are counted in, as best they can be: their tokens and
     * model, not their times.
     */
    private function migrateRequests(\PDO $pdo): void
    {
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 3) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS requests (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    at REAL NOT NULL,
                    model TEXT NOT NULL,
                    input INTEGER NOT NULL,
                    cached INTEGER NOT NULL,
                    output INTEGER NOT NULL,
                    first_ms INTEGER,
                    total_ms INTEGER
                )
                SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS requests_at ON requests(at)');
            $pdo->exec(<<<'SQL'
                INSERT INTO requests (at, model, input, cached, output, first_ms, total_ms)
                SELECT CAST(strftime('%s', e.created_at) AS REAL),
                    CASE WHEN json_extract(e.data, '$.meta.model') LIKE '%/%' THEN json_extract(e.data, '$.meta.model') ELSE s.model END,
                    COALESCE(json_extract(e.data, '$.meta.usage.input'), 0),
                    COALESCE(json_extract(e.data, '$.meta.usage.cache_read'), 0),
                    COALESCE(json_extract(e.data, '$.meta.usage.output'), 0),
                    NULL, NULL
                FROM entries e JOIN sessions s ON s.id = e.session_id
                WHERE e.type = 'message' AND json_extract(e.data, '$.role') = 'assistant'
                    AND json_extract(e.data, '$.meta.usage') IS NOT NULL
                SQL);
            $pdo->exec('PRAGMA user_version = 3');
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    /**
     * Records one answer from a model.
     *
     * @param array{at: float, model: string, input: int, cached: int, output: int, first_ms: ?int, total_ms: int} $request
     */
    public function record(array $request): void
    {
        ($this->recordStatement ??= $this->open()->prepare(
            'INSERT INTO requests (at, model, input, cached, output, first_ms, total_ms) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ))->execute([
            $request['at'], $request['model'], $request['input'], $request['cached'], $request['output'], $request['first_ms'], $request['total_ms'],
        ]);
    }

    /**
     * Requests answered since a time, summed by day (local time), by model
     * or by provider: their count,
     * tokens, the mean first-token time, and output tokens a second while
     * streaming.
     *
     * @return list<array{key: string, requests: int, input: int, cached: int, output: int, first_ms: ?int, tps: ?float}>
     */
    public function usage(float $since, string $by, ?string $model = null): array
    {
        $key = match ($by) {
            'day' => "strftime('%Y-%m-%d', at, 'unixepoch', 'localtime')",
            'model' => 'model',
            'provider' => "CASE WHEN instr(model, '/') > 0 THEN substr(model, 1, instr(model, '/') - 1) ELSE model END",
            default => throw new \InvalidArgumentException("Usage is by day, model or provider, not {$by}."),
        };
        // Timed from its first token to its end: the speed of the stream, not of the wait before it.
        $streamed = 'first_ms IS NOT NULL AND total_ms > first_ms AND output > 0';
        $statement = $this->open()->prepare(<<<SQL
            SELECT {$key} AS key, COUNT(*) AS requests,
                SUM(input) AS input, SUM(cached) AS cached, SUM(output) AS output,
                CAST(AVG(first_ms) AS INTEGER) AS first_ms,
                SUM(CASE WHEN {$streamed} THEN output END) * 1000.0 / SUM(CASE WHEN {$streamed} THEN total_ms - first_ms END) AS tps
            FROM requests WHERE at >= ? AND (? IS NULL OR model = ?)
            GROUP BY key ORDER BY {$this->order($by)}
            SQL);
        $statement->execute([$since, $model, $model]);
        return array_map(static fn (array $row): array => [
            'key' => (string) $row['key'],
            'requests' => (int) $row['requests'],
            'input' => (int) $row['input'],
            'cached' => (int) $row['cached'],
            'output' => (int) $row['output'],
            'first_ms' => $row['first_ms'] === null ? null : (int) $row['first_ms'],
            'tps' => $row['tps'] === null ? null : (float) $row['tps'],
        ], $statement->fetchAll());
    }

    /**
     * The day a time falls on, in local time, as usage groups days: the
     * system's, which PHP's own zone may not be.
     */
    public function localDate(float $at): string
    {
        $statement = $this->open()->prepare("SELECT strftime('%Y-%m-%d', ?, 'unixepoch', 'localtime')");
        $statement->execute([$at]);
        return (string) $statement->fetchColumn();
    }

    private function order(string $by): string
    {
        return in_array($by, ['model', 'provider'], true) ? 'requests DESC' : 'key';
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

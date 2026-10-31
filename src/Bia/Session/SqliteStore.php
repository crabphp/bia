<?php

declare(strict_types=1);

namespace Bia\Session;

use Bia\Contracts\Message;
use Bia\Contracts\SessionStore;

/**
 * The local SQLite conversation store. It opens and migrates lazily, so
 * commands such as `bia --help` never touch the filesystem database.
 */
final class SqliteStore implements SessionStore
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $path) {}

    public function create(string $workspace, string $model, ?string $id = null): string
    {
        $this->open();
        $id ??= self::id();
        $now = gmdate('c');
        $statement = $this->pdo->prepare(
            'INSERT INTO sessions (id, workspace, model, created_at, updated_at) VALUES (:id, :workspace, :model, :created, :updated)',
        );
        $statement->execute([
            'id' => $id,
            'workspace' => $workspace,
            'model' => $model,
            'created' => $now,
            'updated' => $now,
        ]);
        return $id;
    }

    public function append(string $sessionId, Message $message): int
    {
        $this->open();
        $this->pdo->beginTransaction();
        try {
            $next = $this->pdo->prepare('SELECT COALESCE(MAX(sequence), 0) + 1 FROM messages WHERE session_id = :session');
            $next->execute(['session' => $sessionId]);
            $sequence = (int) $next->fetchColumn();
            $insert = $this->pdo->prepare(
                'INSERT INTO messages (session_id, sequence, role, content, metadata, created_at) VALUES (:session, :sequence, :role, :content, :metadata, :created)',
            );
            $insert->execute([
                'session' => $sessionId,
                'sequence' => $sequence,
                'role' => $message->role,
                'content' => json_encode($message->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'metadata' => json_encode($message->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created' => gmdate('c'),
            ]);
            $update = $this->pdo->prepare('UPDATE sessions SET updated_at = :updated WHERE id = :id');
            $update->execute(['updated' => gmdate('c'), 'id' => $sessionId]);
            $this->pdo->commit();
            return $sequence;
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }

    public function messages(string $sessionId): array
    {
        $this->open();
        $statement = $this->pdo->prepare(
            'SELECT role, content, metadata FROM messages WHERE session_id = :session ORDER BY sequence ASC',
        );
        $statement->execute(['session' => $sessionId]);
        $messages = [];
        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $content = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);
            $metadata = json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_string($content) && !is_array($content)) {
                throw new \RuntimeException("Session {$sessionId} contains invalid message content.");
            }
            if (!is_array($metadata)) {
                throw new \RuntimeException("Session {$sessionId} contains invalid message metadata.");
            }
            $messages[] = new Message((string) $row['role'], $content, $metadata);
        }
        return $messages;
    }

    public function exists(string $sessionId): bool
    {
        $this->open();
        $statement = $this->pdo->prepare('SELECT 1 FROM sessions WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $sessionId]);
        return $statement->fetchColumn() !== false;
    }

    public function list(int $limit = 50): array
    {
        $this->open();
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            'SELECT s.id, s.workspace, s.model, s.created_at, s.updated_at, COUNT(m.id) AS message_count '
            . 'FROM sessions s LEFT JOIN messages m ON m.session_id = s.id '
            . 'GROUP BY s.id ORDER BY s.updated_at DESC LIMIT ' . $limit,
        );
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    private function open(): void
    {
        if ($this->pdo !== null) {
            return;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Cannot create Bia state directory {$directory}.");
        }
        $this->pdo = new \PDO('sqlite:' . $this->path, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 2500');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                workspace TEXT NOT NULL,
                model TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
                sequence INTEGER NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                metadata TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (session_id, sequence)
            )
        SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS messages_session_sequence ON messages(session_id, sequence)');
    }

    private static function id(): string
    {
        return bin2hex(random_bytes(12));
    }
}

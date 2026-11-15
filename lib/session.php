<?php
// Sessions are stored in the SQLite database instead of PHP's default session files.
// On Azure App Service /home is a network share, where the file handler can fail to delete the old
// session file during session_regenerate_id(true): the call aborts, nothing is saved, and the visitor
// never gets past the password gate. The database already works there, so the sessions live in it.
defined('BUGGY') or exit;

class SqliteSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    // updateTimestamp() only rewrites the expiry when it is at least this much older than a fresh one,
    // so a request that changes nothing does not cost a database write.
    private const REFRESH_AFTER = 3600;

    private PDO $db;
    private int $lifetime;

    public function __construct(PDO $db, int $lifetime)
    {
        $this->db = $db;
        $this->lifetime = $lifetime;
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions (
            id TEXT PRIMARY KEY,
            data TEXT NOT NULL,
            expires INTEGER NOT NULL
        )');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires)');
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->db->prepare('SELECT data FROM sessions WHERE id = ? AND expires > ?');
            $stmt->execute([$id, time()]);
            $data = $stmt->fetchColumn();
            return $data === false ? '' : (string) $data;
        } catch (PDOException $e) {
            return $this->failed('read', $e);
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->db->prepare('INSERT OR REPLACE INTO sessions (id, data, expires) VALUES (?, ?, ?)')
                ->execute([$id, $data, time() + $this->lifetime]);
            return true;
        } catch (PDOException $e) {
            return $this->failed('write', $e);
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
            return true;
        } catch (PDOException $e) {
            return $this->failed('destroy', $e);
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM sessions WHERE expires <= ?');
            $stmt->execute([time()]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            return $this->failed('gc', $e);
        }
    }

    // Strict mode: a session id the browser sends that we do not know is replaced by a fresh one.
    public function validateId(string $id): bool
    {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM sessions WHERE id = ? AND expires > ?');
            $stmt->execute([$id, time()]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return $this->failed('validateId', $e);
        }
    }

    // Called instead of write() when the session data did not change.
    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $now = time();
            $this->db->prepare('UPDATE sessions SET expires = ? WHERE id = ? AND expires < ?')
                ->execute([$now + $this->lifetime, $id, $now + $this->lifetime - self::REFRESH_AFTER]);
            return true;
        } catch (PDOException $e) {
            return $this->failed('updateTimestamp', $e);
        }
    }

    // Log the real reason instead of failing silently, then report failure to PHP.
    private function failed(string $what, PDOException $e): bool
    {
        error_log('Buggy Trip session ' . $what . ' failed: ' . $e->getMessage());
        return false;
    }
}

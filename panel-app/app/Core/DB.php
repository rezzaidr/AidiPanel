<?php
declare(strict_types=1);
namespace Core;

class DB
{
    private static ?self $instance = null;
    private \PDO $pdo;

    private function __construct()
    {
        $dbPath = PANEL_DIR . '/storage/db/aidipanel.sqlite';
        $dir    = dirname($dbPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $this->pdo = new \PDO(
            'sqlite:' . $dbPath,
            null,
            null,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT            => 5,
            ]
        );

        $this->pdo->exec('PRAGMA journal_mode=WAL;');
        $this->pdo->exec('PRAGMA synchronous=NORMAL;');
        $this->pdo->exec('PRAGMA foreign_keys=ON;');

        $this->migrate();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE,
                password_hash TEXT    NOT NULL,
                role          TEXT    NOT NULL DEFAULT 'admin',
                active        INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
                last_login    TEXT
            );

            CREATE TABLE IF NOT EXISTS sites (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                domain        TEXT    NOT NULL UNIQUE,
                type          TEXT    NOT NULL DEFAULT 'php',
                php_version   TEXT    NOT NULL DEFAULT '8.3',
                webroot       TEXT    NOT NULL,
                site_user     TEXT,
                ssl_type      TEXT    NOT NULL DEFAULT 'self-signed',
                cache_enabled INTEGER NOT NULL DEFAULT 0,
                created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS activity_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER,
                action     TEXT NOT NULL,
                detail     TEXT,
                ip         TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS metrics (
                ts     INTEGER PRIMARY KEY,   -- unix epoch (one sample per minute via cron)
                cpu    REAL NOT NULL DEFAULT 0,
                mem    REAL NOT NULL DEFAULT 0,
                disk   REAL NOT NULL DEFAULT 0,
                l1     REAL NOT NULL DEFAULT 0,
                l5     REAL NOT NULL DEFAULT 0,
                l15    REAL NOT NULL DEFAULT 0,
                net_rx REAL NOT NULL DEFAULT 0,  -- bytes/sec
                net_tx REAL NOT NULL DEFAULT 0,
                dio_r  REAL NOT NULL DEFAULT 0,  -- bytes/sec
                dio_w  REAL NOT NULL DEFAULT 0
            );
        ");

        // Additive migrations (CREATE TABLE IF NOT EXISTS won't alter an existing table).
        $this->addColumnIfMissing('sites', 'site_user', 'TEXT');

        // Seed admin HANYA jika belum ada user sama sekali
        // Password hash sudah ditulis oleh deploy-panel.sh via CLI —
        // TIDAK dibaca dari file saat runtime untuk menghindari permission issues
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            // Fallback: jika dipanggil tanpa deploy-panel.sh
            // Baca dari env var yang di-set oleh Nginx fastcgi_param
            $hash = getenv('AIDIPANEL_ADMIN_HASH') ?: '';
            if (empty($hash)) {
                // Last resort: password random, tulis ke /tmp agar bisa dibaca admin
                $random = bin2hex(random_bytes(10));
                $hash   = password_hash($random, PASSWORD_BCRYPT, ['cost' => 12]);
                $fallbackFile = '/tmp/aidipanel-fallback-pass.txt';
                @file_put_contents($fallbackFile, "admin password (fallback): {$random}\n");
                @chmod($fallbackFile, 0600);
                error_log("AidiPanel FALLBACK: admin pass written to {$fallbackFile}");
            }
            $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)'
            )->execute(['admin', $hash, 'admin']);
        }
    }

    /** Add a column to an existing table only if it's missing (SQLite-safe). */
    private function addColumnIfMissing(string $table, string $column, string $type): void
    {
        $cols = $this->pdo->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_COLUMN, 1);
        if (!in_array($column, $cols, true)) {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
        }
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function rows(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function row(string $sql, array $params = []): ?array
    {
        $result = $this->run($sql, $params)->fetch();
        return $result ?: null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->row($sql, $params);
        return $row ? reset($row) : null;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public static function log(string $action, string $detail = ''): void
    {
        $db     = self::instance();
        $userId = \Core\Session::get('user_id');
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db->run(
            'INSERT INTO activity_log (user_id, action, detail, ip) VALUES (?, ?, ?, ?)',
            [$userId, $action, $detail, $ip]
        );
    }
}

<?php
declare(strict_types=1);
namespace Core;

class DB
{
    private static ?self $instance = null;
    private \PDO $pdo;
    private bool $immediateTransactionActive = false;

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
                php_version   TEXT    NOT NULL DEFAULT '8.5',
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

            CREATE TABLE IF NOT EXISTS failed_logins (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                username     TEXT,
                ip           TEXT,
                attempted_at INTEGER NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_failed_logins_ip ON failed_logins(ip, attempted_at);
            CREATE INDEX IF NOT EXISTS idx_failed_logins_user ON failed_logins(username, attempted_at);

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

            CREATE TABLE IF NOT EXISTS traffic_metrics (
                minute        INTEGER NOT NULL,
                domain        TEXT    NOT NULL,
                requests      INTEGER NOT NULL DEFAULT 0,
                cache_hits    INTEGER NOT NULL DEFAULT 0,
                cache_misses  INTEGER NOT NULL DEFAULT 0,
                cache_bypass  INTEGER NOT NULL DEFAULT 0,
                cache_bytes   INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (minute, domain)
            );

            CREATE INDEX IF NOT EXISTS idx_traffic_metrics_domain_minute
                ON traffic_metrics(domain, minute);

            CREATE TABLE IF NOT EXISTS traffic_cursors (
                log_path    TEXT    PRIMARY KEY,
                inode       INTEGER NOT NULL,
                byte_offset INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS traffic_state (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS user_recovery_codes (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id   INTEGER NOT NULL,
                code_hash TEXT    NOT NULL,
                used_at   TEXT,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS user_sites (
                user_id INTEGER NOT NULL,
                site_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, site_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            );
        ");

        // Additive migrations (CREATE TABLE IF NOT EXISTS won't alter an existing table).
        $this->addColumnIfMissing('sites', 'site_user', 'TEXT');

        // Per-site PHP settings tuning (JSON of the 8 fields) + free-form additional
        // php.ini directives. Rendered to storage/php-settings/<site_user>.conf on save.
        $this->addColumnIfMissing('sites', 'php_settings', 'TEXT');
        $this->addColumnIfMissing('sites', 'php_extra', 'TEXT');

        // App flavor for reverse-proxy sites: 'nodejs' | 'python' | NULL (generic).
        // Cosmetic identity only — the vhost is a plain reverse proxy either way.
        $this->addColumnIfMissing('sites', 'app_flavor', 'TEXT');

        // Account-settings profile fields (self-service Settings → Profile tab).
        $this->addColumnIfMissing('users', 'email',      'TEXT');
        $this->addColumnIfMissing('users', 'first_name', 'TEXT');
        $this->addColumnIfMissing('users', 'last_name',  'TEXT');
        $this->addColumnIfMissing('users', 'timezone',   "TEXT DEFAULT 'UTC'");

        // Two-Factor Authentication (TOTP) — see _private/specs/2026-06-23-panel-2fa-totp-design.md
        $this->addColumnIfMissing('users', 'totp_secret',       'TEXT');
        $this->addColumnIfMissing('users', 'totp_enabled',      'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing('users', 'totp_confirmed_at', 'TEXT');
        $this->addColumnIfMissing('users', 'totp_last_step',    'INTEGER');

        // Seed admin ONLY if there is no user at all yet
        // Password hash is written by deploy-panel.sh via the CLI -
            // NOT read from a file at runtime, to avoid permission issues
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count === 0) {
            $hash = getenv('AIDIPANEL_ADMIN_HASH') ?: '';
            if ($hash === '') {
                throw new \RuntimeException('Initial admin hash is required.');
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

    /**
     * Run an atomic SQLite write transaction while holding the writer lock from
     * the first statement. This prevents two concurrent last-admin checks from
     * both observing the same stale administrator count.
     */
    public function immediateTransaction(callable $callback): mixed
    {
        if ($this->immediateTransactionActive) {
            return $callback($this);
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->immediateTransactionActive = true;
        try {
            $result = $callback($this);
            $this->pdo->exec('COMMIT');
            $this->immediateTransactionActive = false;
            return $result;
        } catch (\Throwable $e) {
            if ($this->immediateTransactionActive) {
                $this->pdo->exec('ROLLBACK');
                $this->immediateTransactionActive = false;
            }
            throw $e;
        }
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

    /**
     * Count recent failed logins for an IP and a username within their windows.
     * Returns ['ip' => int, 'user' => int].
     */
    public static function failedLoginCounts(string $username, string $ip): array
    {
        $db  = self::instance();
        $now = time();
        $ipCount = (int) $db->value(
            'SELECT COUNT(*) FROM failed_logins WHERE ip = ? AND attempted_at > ?',
            [$ip, $now - 300]            // 5 minutes
        );
        $userCount = (int) $db->value(
            'SELECT COUNT(*) FROM failed_logins WHERE username = ? AND attempted_at > ?',
            [$username, $now - 900]      // 15 minutes
        );
        return ['ip' => $ipCount, 'user' => $userCount];
    }

    /** Record one failed login attempt (and prune old rows). */
    public static function recordFailedLogin(string $username, string $ip): void
    {
        $db = self::instance();
        $db->run(
            'INSERT INTO failed_logins (username, ip, attempted_at) VALUES (?, ?, ?)',
            [$username, $ip, time()]
        );
        $db->run('DELETE FROM failed_logins WHERE attempted_at < ?', [time() - 900]);
    }

    /** Clear an IP's (and optionally a username's) failed attempts; prune old rows. */
    public static function clearFailedLogins(string $ip, string $username = ''): void
    {
        $db = self::instance();
        $db->run('DELETE FROM failed_logins WHERE ip = ?', [$ip]);
        if ($username !== '') {
            $db->run('DELETE FROM failed_logins WHERE username = ?', [$username]);
        }
        $db->run('DELETE FROM failed_logins WHERE attempted_at < ?', [time() - 900]);
    }
}

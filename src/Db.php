<?php
declare(strict_types=1);

/**
 * SQLite access and schema migrations (PRAGMA user_version).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dir = (string) Config::get('data_dir');
        foreach (['', '/media', '/tmp'] as $sub) {
            if (!is_dir($dir . $sub) && !mkdir($dir . $sub, 0750, true) && !is_dir($dir . $sub)) {
                throw new RuntimeException('Cannot create data directory: ' . $dir . $sub);
            }
        }
        $pdo = new PDO('sqlite:' . $dir . '/startseite.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        self::migrate($pdo);
        return self::$pdo = $pdo;
    }

    private static function migrate(PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE sessions (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    selector TEXT NOT NULL UNIQUE,
    validator_hash TEXT NOT NULL,
    csrf TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    ip TEXT,
    ua TEXT
);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY,
    ip TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX idx_login_attempts ON login_attempts(ip, ts);
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE TABLE groups (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE dials (
    id INTEGER PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL,
    url TEXT NOT NULL,
    thumb_mode TEXT NOT NULL DEFAULT 'auto',
    thumb_src TEXT,
    thumb_file TEXT,
    thumb_kind TEXT,
    fit TEXT NOT NULL DEFAULT 'auto',
    thumb_status TEXT NOT NULL DEFAULT 'none',
    thumb_error TEXT,
    thumb_attempts INTEGER NOT NULL DEFAULT 0,
    visits INTEGER NOT NULL DEFAULT 0,
    last_visit INTEGER,
    created_at INTEGER NOT NULL
);
CREATE INDEX idx_dials_group ON dials(group_id, position);
CREATE INDEX idx_dials_status ON dials(thumb_status);
SQL);
            $pdo->exec('PRAGMA user_version=1');
            $pdo->exec('COMMIT');
        }
        if ($version < 2) {
            // Link checks: check_status = ok|cert|suspect|dead|skipped|excluded; check_req = 1 queued, 2 running.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
ALTER TABLE dials ADD COLUMN no_check INTEGER NOT NULL DEFAULT 0;
ALTER TABLE dials ADD COLUMN check_status TEXT;
ALTER TABLE dials ADD COLUMN check_reason TEXT;
ALTER TABLE dials ADD COLUMN check_code INTEGER;
ALTER TABLE dials ADD COLUMN check_at INTEGER;
ALTER TABLE dials ADD COLUMN check_fails INTEGER NOT NULL DEFAULT 0;
ALTER TABLE dials ADD COLUMN check_req INTEGER NOT NULL DEFAULT 0;
ALTER TABLE groups ADD COLUMN no_check INTEGER NOT NULL DEFAULT 0;
CREATE INDEX idx_dials_check ON dials(check_req, check_at);
SQL);
            $pdo->exec('PRAGMA user_version=2');
            $pdo->exec('COMMIT');
        }
        if ($version < 3) {
            // Tags (JSON array), note, page description for search, per-tile colours and badge, "keep" = dismissed from clean-up suggestions.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
ALTER TABLE dials ADD COLUMN tags TEXT NOT NULL DEFAULT '[]';
ALTER TABLE dials ADD COLUMN note TEXT NOT NULL DEFAULT '';
ALTER TABLE dials ADD COLUMN meta_desc TEXT;
ALTER TABLE dials ADD COLUMN color TEXT;
ALTER TABLE dials ADD COLUMN badge TEXT;
ALTER TABLE dials ADD COLUMN badge_color TEXT;
ALTER TABLE dials ADD COLUMN keep INTEGER NOT NULL DEFAULT 0;
SQL);
            $pdo->exec('PRAGMA user_version=3');
            $pdo->exec('COMMIT');
        }
        if ($version < 4) {
            // Optional second factor: TOTP (secret sealed with the server key), passkeys, one-time recovery codes.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
ALTER TABLE users ADD COLUMN totp_secret TEXT;
ALTER TABLE users ADD COLUMN totp_pending TEXT;
ALTER TABLE users ADD COLUMN totp_last_step INTEGER NOT NULL DEFAULT 0;
CREATE TABLE passkeys (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    cred_id TEXT NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    sign_count INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    last_used INTEGER
);
CREATE TABLE recovery_codes (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    used_at INTEGER
);
CREATE TABLE challenges (
    id INTEGER PRIMARY KEY,
    hash TEXT NOT NULL UNIQUE,
    kind TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE mfa_attempts (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    ts INTEGER NOT NULL
);
SQL);
            $pdo->exec('PRAGMA user_version=4');
            $pdo->exec('COMMIT');
        }
        if ($version < 5) {
            // Sections inside a tab: own look (frame, colour, width), tiles or list. Every dial belongs to one section.
            // kind/widget_* are reserved for later widget sections; only 'bookmarks' exists so far.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
CREATE TABLE sections (
    id INTEGER PRIMARY KEY,
    group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    position INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL DEFAULT '',
    title_center INTEGER NOT NULL DEFAULT 0,
    collapsed INTEGER NOT NULL DEFAULT 0,
    width INTEGER NOT NULL DEFAULT 100,
    mode TEXT NOT NULL DEFAULT 'inherit',
    cols INTEGER NOT NULL DEFAULT 1,
    tile_size TEXT NOT NULL DEFAULT '',
    ratio TEXT NOT NULL DEFAULT '',
    bg_color TEXT NOT NULL DEFAULT '',
    bg_opacity INTEGER NOT NULL DEFAULT 30,
    border_color TEXT NOT NULL DEFAULT '',
    border_width INTEGER NOT NULL DEFAULT 0,
    radius INTEGER NOT NULL DEFAULT 14,
    padding INTEGER NOT NULL DEFAULT 14,
    kind TEXT NOT NULL DEFAULT 'bookmarks',
    widget_type TEXT NOT NULL DEFAULT '',
    widget_config TEXT NOT NULL DEFAULT '{}'
);
CREATE INDEX idx_sections_group ON sections(group_id, position);
ALTER TABLE dials ADD COLUMN section_id INTEGER;
INSERT INTO sections (group_id, position, padding) SELECT id, 0, 0 FROM groups;
UPDATE dials SET section_id = (SELECT s.id FROM sections s WHERE s.group_id = dials.group_id ORDER BY s.position, s.id LIMIT 1);
SQL);
            $pdo->exec('PRAGMA user_version=5');
            $pdo->exec('COMMIT');
        }
        if ($version < 6) {
            // Cropping keeps the uncropped image (thumb_orig) so the cut can be changed or undone; thumb_crop is the rectangle.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
ALTER TABLE dials ADD COLUMN thumb_orig TEXT;
ALTER TABLE dials ADD COLUMN thumb_orig_kind TEXT;
ALTER TABLE dials ADD COLUMN thumb_crop TEXT;
SQL);
            $pdo->exec('PRAGMA user_version=6');
            $pdo->exec('COMMIT');
        }
        if ($version < 7) {
            // Sections with width 0 size themselves to their content; tile_cols is the most tiles they put side by side.
            $pdo->exec('BEGIN');
            $pdo->exec('ALTER TABLE sections ADD COLUMN tile_cols INTEGER NOT NULL DEFAULT 4');
            $pdo->exec('PRAGMA user_version=7');
            $pdo->exec('COMMIT');
        }
        if ($version < 8) {
            // Cropped images used to be marked as screenshots (no margin); they keep the kind of the original now.
            $pdo->exec('BEGIN');
            $pdo->exec('UPDATE dials SET thumb_kind = thumb_orig_kind WHERE thumb_orig IS NOT NULL AND thumb_orig_kind IS NOT NULL');
            $pdo->exec('PRAGMA user_version=8');
            $pdo->exec('COMMIT');
        }
        if ($version < 9) {
            // Several users: e-mail login, roles, per-user data and settings, one-time tokens (verify, reset), rate limits.
            // Everything that exists so far goes to the first user, who becomes the admin.
            $pdo->exec('BEGIN');
            $pdo->exec(<<<'SQL'
ALTER TABLE users ADD COLUMN email TEXT;
ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user';
ALTER TABLE users ADD COLUMN lang TEXT NOT NULL DEFAULT 'de';
ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN last_login INTEGER;
CREATE UNIQUE INDEX idx_users_email ON users(email) WHERE email IS NOT NULL;
ALTER TABLE groups ADD COLUMN user_id INTEGER REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE dials ADD COLUMN user_id INTEGER;
ALTER TABLE sections ADD COLUMN user_id INTEGER;
UPDATE users SET role = 'admin' WHERE id = (SELECT MIN(id) FROM users);
UPDATE groups SET user_id = (SELECT MIN(id) FROM users);
UPDATE dials SET user_id = (SELECT MIN(id) FROM users);
UPDATE sections SET user_id = (SELECT MIN(id) FROM users);
CREATE INDEX idx_groups_user ON groups(user_id, position);
CREATE INDEX idx_dials_user ON dials(user_id);
CREATE INDEX idx_sections_user ON sections(user_id);
CREATE TABLE user_settings (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (user_id, key)
);
INSERT INTO user_settings (user_id, key, value) SELECT (SELECT MIN(id) FROM users), key, value FROM settings WHERE (SELECT COUNT(*) FROM users) > 0;
DELETE FROM settings;
CREATE TABLE tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    hash TEXT NOT NULL UNIQUE,
    data TEXT,
    expires_at INTEGER NOT NULL
);
CREATE TABLE rate (
    kind TEXT NOT NULL,
    k TEXT NOT NULL,
    ts INTEGER NOT NULL
);
CREATE INDEX idx_rate ON rate(kind, k, ts);
SQL);
            $pdo->exec('PRAGMA user_version=9');
            $pdo->exec('COMMIT');
            self::moveLegacyBackups((int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0));
        }
    }

    /** Backups used to sit in one folder; they now live in backups/u<id>/ and belong to the first user. */
    private static function moveLegacyBackups(int $firstUser): void
    {
        $dir = (string) Config::get('data_dir') . '/backups';
        if ($firstUser < 1 || !is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/startseite-*.zip') ?: [];
        if ($files && !is_dir($dir . '/u' . $firstUser)) {
            @mkdir($dir . '/u' . $firstUser, 0750, true);
        }
        foreach ($files as $f) {
            @rename($f, $dir . '/u' . $firstUser . '/' . basename($f));
        }
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }

    public static function mediaDir(): string
    {
        self::pdo();
        return Config::get('data_dir') . '/media';
    }
}

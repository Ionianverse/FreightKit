<?php
/**
 * BO Planning System – server library: settings, database, sessions, encryption, mail.
 *
 * Private data lives OUTSIDE the public web folder, in  <domain folder>/bo-private/ :
 *   config.php   database login, admin e-mail (written once by setup.php)
 *   data.key     the server key that encrypts every saved file (made by setup.php, never leaves the server)
 *   saves/       users' saved work, encrypted (AES-256-GCM)
 * Nothing in this public folder holds a secret except .appkey.php (the tool's code key), which the web server
 * never serves (.htaccess) and which is handed only to signed-in, approved users.
 */
declare(strict_types=1);

const BO_IDLE_SECONDS = 3600;          // signed out after 1 hour without activity
const BO_MAX_SESSION = 12 * 3600;      // and after 12 hours in any case
const BO_MAX_SAVE_BYTES = 60 * 1048576; // one save (all files together)
const BO_KEEP_PER_DAY = 3;             // saves kept per plan date and user
const BO_KEEP_DAYS = 120;              // plan dates kept per user

function bo_private_dir(): string {
    $env = getenv('BO_PRIVATE_DIR');
    if ($env) return rtrim($env, '/\\');
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $base = $root !== '' ? dirname(rtrim($root, '/\\')) : dirname(__DIR__, 2);
    return $base . DIRECTORY_SEPARATOR . 'bo-private';
}

function bo_config(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $f = bo_private_dir() . '/config.php';
    $cfg = is_file($f) ? (require $f) : null;
    // Email addresses live in their own file (set in Users & requests → Email settings); it wins over setup's values.
    if (is_array($cfg)) foreach (bo_mail_settings() as $k => $v) $cfg[$k] = $v;
    return $cfg;
}
function bo_mail_settings_file(): string { return __DIR__ . '/mail-settings.json'; }
function bo_mail_settings(): array {
    $f = bo_mail_settings_file();
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    $out = [];
    foreach (['notify_email', 'mail_from'] as $k) {
        $v = is_array($j) ? strtolower(trim((string)($j[$k] ?? ''))) : '';
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) $out[$k] = $v;
    }
    return $out;
}

function bo_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = bo_config();
    if (!$c) bo_fail(503, 'The BO Planning System is not set up yet.');
    $pdo = new PDO($c['db_dsn'], $c['db_user'] ?? null, $c['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (str_starts_with($c['db_dsn'], 'sqlite:')) $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/** Tables, written so they work on MySQL (Hostinger) and SQLite (local tests). */
function bo_schema(PDO $pdo): void {
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS bo_users (
        id $id, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, company VARCHAR(160) NOT NULL DEFAULT '',
        note VARCHAR(600) NOT NULL DEFAULT '', pw_hash VARCHAR(255) NOT NULL, role VARCHAR(10) NOT NULL DEFAULT 'user',
        status VARCHAR(12) NOT NULL DEFAULT 'pending', must_change INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL, decided_at INT NULL, decided_by INT NULL, last_login INT NULL,
        failed INT NOT NULL DEFAULT 0, locked_until INT NOT NULL DEFAULT 0)$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bo_sessions (
        token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id INT NOT NULL, created_at INT NOT NULL, last_seen INT NOT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '', agent VARCHAR(200) NOT NULL DEFAULT '')$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bo_saves (
        id $id, user_id INT NOT NULL, plan_date VARCHAR(10) NOT NULL, saved_at INT NOT NULL,
        files TEXT NOT NULL, bytes INT NOT NULL DEFAULT 0, note VARCHAR(200) NOT NULL DEFAULT '')$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bo_audit (
        id $id, at INT NOT NULL, actor_id INT NULL, user_id INT NULL, action VARCHAR(40) NOT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT '', ip VARCHAR(64) NOT NULL DEFAULT '')$tail");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bo_attempts (
        id $id, kind VARCHAR(12) NOT NULL, ip VARCHAR(64) NOT NULL, at INT NOT NULL)$tail");
}

/* ---------- responses ---------- */
function bo_headers(): void {
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}
function bo_json($data, int $code = 200): void {
    bo_headers();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function bo_fail(int $code, string $msg, array $extra = []): void { bo_json(['ok' => false, 'error' => $msg] + $extra, $code); }

/** Every call must come from the tool's own pages: a custom header (other sites cannot send it without
 *  permission), and when the browser says where it comes from, the same host. */
function bo_guard(string $method = 'POST'): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) bo_fail(405, 'Method not allowed.');
    if (($_SERVER['HTTP_X_BO_REQUEST'] ?? '') !== '1') bo_fail(403, 'Forbidden.');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (!$host || strcasecmp($host, explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) !== 0) bo_fail(403, 'Forbidden.');
    }
}
function bo_input(): array {
    static $in = null;
    if ($in !== null) return $in;
    $raw = file_get_contents('php://input', false, null, 0, 200000) ?: '';
    $in = json_decode($raw, true);
    return is_array($in) ? $in : [];
}
function bo_str(array $a, string $k, int $max = 500): string {
    $v = trim(str_replace("\0", '', (string)($a[$k] ?? '')));
    return mb_substr($v, 0, $max, 'UTF-8');
}
function bo_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function bo_now(): int { return time(); }
function bo_audit(string $action, ?int $userId = null, ?int $actorId = null, string $detail = ''): void {
    try {
        bo_db()->prepare('INSERT INTO bo_audit (at, actor_id, user_id, action, detail, ip) VALUES (?,?,?,?,?,?)')
            ->execute([bo_now(), $actorId, $userId, $action, mb_substr($detail, 0, 255, 'UTF-8'), bo_ip()]);
    } catch (Throwable $e) { /* the audit trail never blocks the user */ }
}

/** Simple rate limit by IP and kind (sign-in failures, access requests). */
function bo_too_many(string $kind, int $max, int $window): bool {
    $db = bo_db(); $since = bo_now() - $window;
    $db->prepare('DELETE FROM bo_attempts WHERE at < ?')->execute([bo_now() - 86400]);
    $st = $db->prepare('SELECT COUNT(*) FROM bo_attempts WHERE kind = ? AND ip = ? AND at >= ?');
    $st->execute([$kind, bo_ip(), $since]);
    return (int)$st->fetchColumn() >= $max;
}
function bo_attempt(string $kind): void {
    bo_db()->prepare('INSERT INTO bo_attempts (kind, ip, at) VALUES (?,?,?)')->execute([$kind, bo_ip(), bo_now()]);
}

/* ---------- sessions ---------- */
function bo_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function bo_cookie_name(): string { return bo_https() ? '__Host-bo_session' : 'bo_session'; }
function bo_set_cookie(string $value, int $expires): void {
    setcookie(bo_cookie_name(), $value, ['expires' => $expires, 'path' => '/', 'secure' => bo_https(), 'httponly' => true, 'samesite' => 'Strict']);
}
function bo_start_session(int $userId): void {
    $token = bin2hex(random_bytes(32));
    bo_db()->prepare('INSERT INTO bo_sessions (token_hash, user_id, created_at, last_seen, ip, agent) VALUES (?,?,?,?,?,?)')
        ->execute([hash('sha256', $token), $userId, bo_now(), bo_now(), bo_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    bo_set_cookie($token, 0); // a browser-session cookie: closing the browser ends it
}
function bo_end_session(): void {
    $t = $_COOKIE[bo_cookie_name()] ?? '';
    if ($t !== '') bo_db()->prepare('DELETE FROM bo_sessions WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    bo_set_cookie('', 1);
}
/** The signed-in, approved user, or null. Refreshes the idle timer. */
function bo_user(bool $touch = true): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $t = $_COOKIE[bo_cookie_name()] ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $t)) return null;
    $db = bo_db(); $h = hash('sha256', $t); $now = bo_now();
    $st = $db->prepare('SELECT s.created_at, s.last_seen, u.* FROM bo_sessions s JOIN bo_users u ON u.id = s.user_id WHERE s.token_hash = ?');
    $st->execute([$h]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($row['status'] !== 'approved' || $now - (int)$row['last_seen'] > BO_IDLE_SECONDS || $now - (int)$row['created_at'] > BO_MAX_SESSION) {
        $db->prepare('DELETE FROM bo_sessions WHERE token_hash = ?')->execute([$h]);
        return null;
    }
    if ($touch) $db->prepare('UPDATE bo_sessions SET last_seen = ? WHERE token_hash = ?')->execute([$now, $h]);
    $db->prepare('DELETE FROM bo_sessions WHERE last_seen < ?')->execute([$now - BO_IDLE_SECONDS]);
    return $u = $row;
}
function bo_require_user(): array {
    $u = bo_user();
    if (!$u) bo_fail(401, 'Please sign in again.', ['signedOut' => true]);
    return $u;
}
function bo_require_admin(): array {
    $u = bo_require_user();
    if ($u['role'] !== 'admin') bo_fail(403, 'Only the admin can do this.');
    return $u;
}
function bo_public_user(array $u): array {
    return ['id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'company' => $u['company'], 'role' => $u['role'], 'mustChange' => (bool)$u['must_change']];
}
function bo_password_ok(string $p): ?string {
    if (mb_strlen($p, 'UTF-8') < 12) return 'Use at least 12 characters for the password.';
    if (mb_strlen($p, 'UTF-8') > 200) return 'That password is too long.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) return 'Use letters and numbers in the password.';
    return null;
}

/* ---------- encryption of saved files (server key, AES-256-GCM) ---------- */
function bo_data_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $f = bo_private_dir() . '/data.key';
    $raw = is_file($f) ? base64_decode(trim((string)file_get_contents($f)), true) : false;
    if (!$raw || strlen($raw) !== 32) bo_fail(503, 'The server key is missing. Run the setup again.');
    return $k = $raw;
}
function bo_seal(string $plain, string $aad): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', bo_data_key(), OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($ct === false) bo_fail(500, 'Could not encrypt the saved work.');
    return "BO1" . $iv . $tag . $ct;
}
function bo_open(string $blob, string $aad): string {
    if (substr($blob, 0, 3) !== 'BO1' || strlen($blob) < 31) bo_fail(500, 'A saved file is damaged.');
    $pt = openssl_decrypt(substr($blob, 31), 'aes-256-gcm', bo_data_key(), OPENSSL_RAW_DATA, substr($blob, 3, 12), substr($blob, 15, 16), $aad);
    if ($pt === false) bo_fail(500, 'A saved file could not be read.');
    return $pt;
}
function bo_save_dir(int $userId, int $saveId): string {
    return bo_private_dir() . "/saves/u$userId/s$saveId";
}
function bo_rmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) { if ($f !== '.' && $f !== '..') { $p = "$dir/$f"; is_dir($p) ? bo_rmdir($p) : @unlink($p); } }
    @rmdir($dir);
}

/* ---------- mail (Hostinger: PHP mail() from a mailbox of this domain) ---------- */
function bo_mail(string $to, string $subject, string $body): bool {
    $c = bo_config() ?: [];
    $from = filter_var((string)($c['mail_from'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$from || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subject = '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]+/', ' ', $subject)) . '?=';
    $headers = "From: BO Planning System <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: FreightKit";
    if (getenv('BO_MAIL_LOG')) { file_put_contents(getenv('BO_MAIL_LOG'), "TO: $to\nSUBJECT: $subject\n$body\n----\n", FILE_APPEND); return true; }
    return @mail($to, $subject, $body, $headers, "-f$from");
}
function bo_site_url(): string {
    $c = bo_config() ?: [];
    if (!empty($c['site_url'])) return rtrim($c['site_url'], '/');
    return (bo_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

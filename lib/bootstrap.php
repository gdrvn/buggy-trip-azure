<?php
// Shared setup for index.php and api.php: storage, database, session, helpers.
defined('BUGGY') or exit;

error_reporting(E_ALL);
ini_set('display_errors', '0');

const BUGGY_SESSION_DAYS = 30;

function dataDir() {
    $custom = getenv('BUGGY_DB_PATH');
    if ($custom) {
        return dirname($custom);
    }
    if (getenv('WEBSITE_SITE_NAME')) { // set by Azure App Service
        return '/home/data';
    }
    return dirname(__DIR__, 2) . '/buggy-trip-data';
}

function databasePath() {
    $custom = getenv('BUGGY_DB_PATH');
    return $custom ? $custom : dataDir() . '/buggy-trip.sqlite';
}

function ensureDir($dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create folder ' . $dir);
    }
}

// Azure keeps /home on a network share whose file locks SQLite cannot rely on. So every request
// takes an exclusive lock on a local file instead (one request at a time — each takes a few
// milliseconds), and SQLite's own locking is switched off with the "unix-none" VFS.
function openDatabase() {
    static $db = null;
    static $lock = null;
    if ($db) {
        return $db;
    }
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The PHP pdo_sqlite extension is not available on this server.');
    }
    $path = databasePath();
    ensureDir(dirname($path));

    $lock = fopen(sys_get_temp_dir() . '/buggy-trip-' . md5($path) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Server is busy — try again.');
    }
    try {
        $db = new PDO('sqlite:file:' . $path . '?vfs=unix-none');
    } catch (PDOException $e) {
        $db = new PDO('sqlite:' . $path); // PHP versions without URI support
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = DELETE');
    $db->exec('PRAGMA synchronous = FULL');
    $db->exec('PRAGMA foreign_keys = ON');

    require_once __DIR__ . '/schema.php';
    migrate($db);
    return $db;
}

function startAppSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = dataDir() . '/sessions';
    ensureDir($dir);
    $lifetime = BUGGY_SESSION_DAYS * 86400;
    ini_set('session.save_path', $dir);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('buggytrip');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function sitePassword() {
    $pw = getenv('SITE_PASSWORD');
    return ($pw === false || $pw === '') ? null : $pw;
}

function gatePassed() {
    return !empty($_SESSION['gate_ok']);
}

function clientIp() {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $ip = trim(explode(',', $fwd)[0]);
        return preg_replace('/:\d+$/', '', $ip); // Azure appends the port
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Simple brute-force brake: at most $max failures per key in the last $windowMinutes.
function isThrottled($db, $key, $max = 10, $windowMinutes = 15) {
    $db->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', ?)")
        ->execute(['-' . $windowMinutes . ' minutes']);
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ?');
    $stmt->execute([$key]);
    return (int) $stmt->fetchColumn() >= $max;
}

function recordFailure($db, $key) {
    $db->prepare("INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (?, datetime('now'))")->execute([$key]);
}

function clearFailures($db, $key) {
    $db->prepare('DELETE FROM login_attempts WHERE attempt_key = ?')->execute([$key]);
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

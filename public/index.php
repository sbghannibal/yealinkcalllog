<?php
declare(strict_types=1);

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Migrations.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Ingest.php';
require __DIR__ . '/../src/Report.php';
require __DIR__ . '/../src/Setup.php';

use YealinkCallLog\Config;
use YealinkCallLog\Db;
use YealinkCallLog\Migrations;
use YealinkCallLog\Auth;
use YealinkCallLog\Ingest;
use YealinkCallLog\Report;
use YealinkCallLog\Setup;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// Load .env file from the project root (one level above public/).
Config::loadDotEnv(dirname(__DIR__) . '/.env');

$cfg = Config::fromEnv();

// Start the PHP session before any output.
Auth::startSession();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// /logout only needs the session, not the DB.
if ($path === '/logout') {
    Auth::logout();
    header('Location: /login');
    exit;
}

// /yealink/event is always public — phones call this without a session.
// Handle it before the DB connection guard so the 503 path is avoided
// only for this route if DB is down; but we still need the DB to log events,
// so connect normally and fall through to the Ingest handler below.

// All routes below need a DB connection.
try {
    $db = Db::pdo($cfg);
    Migrations::migrate($db);
} catch (\PDOException $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database unavailable. Please check server configuration.\n";
    exit;
}

// ── Public ingest endpoint (Yealink phones) ───────────────────────────────
if ($path === '/yealink/event') {
    Ingest::handle($db, $cfg, $_GET, $_SERVER);
    exit;
}

// ── Login ─────────────────────────────────────────────────────────────────
if ($path === '/login') {
    if (Auth::isLoggedIn()) {
        header('Location: /dashboard');
        exit;
    }
    if ($method === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (Auth::login($db, $username, $password)) {
            header('Location: /dashboard');
            exit;
        }
        Auth::renderLogin('Invalid username or password.');
        exit;
    }
    Auth::renderLogin();
    exit;
}

// ── First-run init wizard (only when no admin exists) ─────────────────────
if ($path === '/init') {
    if (Auth::adminExists($db)) {
        header('Location: /dashboard');
        exit;
    }
    if ($method === 'POST') {
        $username  = trim((string) ($_POST['username']  ?? ''));
        $password  = (string) ($_POST['password']  ?? '');
        $password2 = (string) ($_POST['password2'] ?? '');
        $error = '';
        if ($username === '') {
            $error = 'Username is required.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $password2) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare(
                "INSERT INTO users (username, password, role, ext, created_at) VALUES (:u, :p, 'admin', NULL, NOW(3))"
            );
            $stmt->execute([':u' => $username, ':p' => $hash]);
            header('Location: /login');
            exit;
        }
        Auth::renderInit($error);
        exit;
    }
    Auth::renderInit();
    exit;
}

// ── All routes below require a logged-in user ─────────────────────────────
Auth::requireLogin();
$user = Auth::currentUser();

// If somehow currentUser() is null after requireLogin(), bail out.
if ($user === null) {
    header('Location: /login');
    exit;
}

// ── Admin: user management ────────────────────────────────────────────────
if ($path === '/admin/users') {
    Auth::requireAdmin();
    if ($method === 'POST') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role     = (string) ($_POST['role'] ?? 'user');
        $ext      = trim((string) ($_POST['ext'] ?? ''));
        $error    = '';
        if ($username === '') {
            $error = 'Username is required.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif (!in_array($role, ['admin', 'user'], true)) {
            $error = 'Invalid role.';
        } elseif ($role === 'user' && $ext === '') {
            $error = 'Extension is required for user role.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare(
                    "INSERT INTO users (username, password, role, ext, created_at) VALUES (:u, :p, :r, :e, NOW(3))"
                );
                $stmt->execute([
                    ':u' => $username,
                    ':p' => $hash,
                    ':r' => $role,
                    ':e' => ($role === 'admin') ? null : $ext,
                ]);
                Auth::renderAdminUsers($db, '', "User '{$username}' created successfully.");
                exit;
            } catch (\PDOException $e) {
                $error = (strpos($e->getMessage(), 'Duplicate entry') !== false)
                    ? "Username '{$username}' already exists."
                    : 'Failed to create user.';
            }
        }
        Auth::renderAdminUsers($db, $error);
        exit;
    }
    Auth::renderAdminUsers($db);
    exit;
}

// ── Setup (phone configuration helper) ───────────────────────────────────
if ($path === '/setup') {
    Setup::render($cfg, $_SERVER, $_GET);
    exit;
}

// ── Calls listing ─────────────────────────────────────────────────────────
if ($path === '/calls') {
    Report::renderCalls($db, $cfg, $user, $_GET);
    exit;
}

// ── Extension stats ───────────────────────────────────────────────────────
if ($path === '/extension') {
    Report::renderExtensionStats($db, $cfg, $user, $_GET);
    exit;
}

// ── Dashboard ─────────────────────────────────────────────────────────────
if ($path === '/' || $path === '/dashboard') {
    Report::renderDashboard($db, $cfg, $user, $_GET);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";

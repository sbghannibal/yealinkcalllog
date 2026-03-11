<?php
declare(strict_types=1);

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Migrations.php';
require __DIR__ . '/../src/Ingest.php';
require __DIR__ . '/../src/Report.php';
require __DIR__ . '/../src/Setup.php';

use YealinkCallLog\Config;
use YealinkCallLog\Db;
use YealinkCallLog\Migrations;
use YealinkCallLog\Ingest;
use YealinkCallLog\Report;
use YealinkCallLog\Setup;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$cfg = Config::fromEnv();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method Not Allowed\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// /setup does not need a DB connection — render before attempting to connect.
if ($path === '/setup') {
    Setup::render($cfg, $_SERVER, $_GET);
    exit;
}

// All other routes need the DB (and run auto-migration on every request,
// but the migration itself is a no-op after the first run).
try {
    $db = Db::pdo($cfg);
    Migrations::migrate($db);
} catch (\PDOException $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database unavailable. Please check server configuration.\n";
    exit;
}

if ($path === '/yealink/event') {
    Ingest::handle($db, $cfg, $_GET, $_SERVER);
    exit;
}

if ($path === '/extension') {
    Report::renderExtensionStats($db, $cfg, $_GET);
    exit;
}

if ($path === '/' || $path === '/dashboard') {
    Report::renderDashboard($db, $cfg, $_GET);
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";

<?php
declare(strict_types=1);

require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Migrations.php';
require __DIR__ . '/../src/Ingest.php';
require __DIR__ . '/../src/Report.php';
require __DIR__ . '/../src/Setup.php';
require __DIR__ . '/../src/Theme.php';
require __DIR__ . '/../src/Auth.php';

use YealinkCallLog\Config;
use YealinkCallLog\Db;
use YealinkCallLog\Migrations;
use YealinkCallLog\Ingest;
use YealinkCallLog\Report;
use YealinkCallLog\Setup;
use YealinkCallLog\Auth;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$cfg = Config::fromEnv();
Auth::startSession();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/yealink/event') {
    if ($method !== 'GET') { http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit; }
    try { $db = Db::pdo($cfg); Migrations::migrate($db); }
    catch (\PDOException) { http_response_code(503); header('Content-Type:text/plain; charset=utf-8'); echo "Database unavailable.\n"; exit; }
    Ingest::handle($db, $cfg, $_GET, $_SERVER);
    exit;
}

try { $db = Db::pdo($cfg); Migrations::migrate($db); }
catch (\PDOException) { http_response_code(503); header('Content-Type:text/plain; charset=utf-8'); echo "Database unavailable.\n"; exit; }

if ($path === '/init') {
    if (Auth::adminExists($db)) { header('Location: /login'); exit; }
    if ($method === 'GET') { Auth::renderInit(); exit; }
    if ($method === 'POST') {
        $u=trim((string)($_POST['username']??'')); $p1=(string)($_POST['password']??''); $p2=(string)($_POST['password2']??'');
        if ($u===''||$p1===''||$p2==='') { Auth::renderInit('All fields are required.'); exit; }
        if ($p1!==$p2) { Auth::renderInit('Passwords do not match.'); exit; }
        try { Auth::createUser($db,$u,$p1,'admin',null); } catch (\Throwable $e) { Auth::renderInit($e->getMessage()); exit; }
        Auth::login($db,$u,$p1); header('Location: /dashboard'); exit;
    }
    http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit;
}

if ($path === '/login') {
    if ($method === 'GET') { Auth::renderLogin(); exit; }
    if ($method === 'POST') {
        $u=trim((string)($_POST['username']??'')); $p=(string)($_POST['password']??'');
        if ($u===''||$p==='') { Auth::renderLogin('Username and password are required.'); exit; }
        if (Auth::login($db,$u,$p)) { header('Location: /dashboard'); exit; }
        Auth::renderLogin('Invalid username or password.'); exit;
    }
    http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit;
}

if ($path === '/logout') { Auth::logout(); header('Location: /login'); exit; }

Auth::requireLogin();
$user = Auth::currentUser();
if ($user === null) { header('Location: /login'); exit; }

if ($path === '/setup') { Auth::requireAdmin(); Setup::render($cfg, $_SERVER, $_GET); exit; }

if ($path === '/admin/users') {
    Auth::requireAdmin();
    if ($method === 'GET') { Auth::renderAdminUsers($db); exit; }
    if ($method === 'POST') {
        $u=trim((string)($_POST['username']??'')); $p=(string)($_POST['password']??''); $r=(string)($_POST['role']??'user'); $e=isset($_POST['ext'])?(string)$_POST['ext']:null;
        try { Auth::createUser($db,$u,$p,$r,$e); Auth::renderAdminUsers($db,'','User created.'); }
        catch (\Throwable $ex) { Auth::renderAdminUsers($db,$ex->getMessage(),''); }
        exit;
    }
    http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit;
}

if ($path === '/admin/users/password') {
    Auth::requireAdmin();
    if ($method !== 'POST') { http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit; }
    $id=(int)($_POST['id']??0); $pw=(string)($_POST['password']??'');
    try { Auth::setPassword($db,$id,$pw); Auth::renderAdminUsers($db,'','Password updated.'); }
    catch (\Throwable $ex) { Auth::renderAdminUsers($db,$ex->getMessage(),''); }
    exit;
}

if ($path === '/admin/users/delete') {
    Auth::requireAdmin();
    if ($method !== 'POST') { http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit; }
    $id=(int)($_POST['id']??0); $me=(int)($user['id']??0);
    try { Auth::deleteUser($db,$id,$me); Auth::renderAdminUsers($db,'','User deleted.'); }
    catch (\Throwable $ex) { Auth::renderAdminUsers($db,$ex->getMessage(),''); }
    exit;
}

// NEW: set allowed extensions for team lead
if ($path === '/admin/users/teamlead-exts') {
    Auth::requireAdmin();
    if ($method !== 'POST') { http_response_code(405); header('Content-Type:text/plain; charset=utf-8'); echo "Method Not Allowed\n"; exit; }

    $id  = (int)($_POST['id'] ?? 0);
    $raw = trim((string)($_POST['exts'] ?? ''));

    // parse "101,102, 103" into array
    $parts = $raw === '' ? [] : (preg_split('/\s*,\s*/', $raw) ?: []);
    $exts = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '') $exts[] = $p;
    }

    try {
        Auth::setTeamLeadExtensions($db, $id, $exts);
        Auth::renderAdminUsers($db, '', 'Team lead extensions updated.');
    } catch (\Throwable $ex) {
        Auth::renderAdminUsers($db, $ex->getMessage(), '');
    }
    exit;
}

if ($path === '/' || $path === '/dashboard') { Report::renderDashboard($db,$cfg,$user,$_GET); exit; }
if ($path === '/extension') { Report::renderExtensionStats($db,$cfg,$user,$_GET); exit; }
if ($path === '/calls') { Report::renderCalls($db,$cfg,$user,$_GET); exit; }

http_response_code(404);
header('Content-Type:text/plain; charset=utf-8');
echo "Not Found\n";
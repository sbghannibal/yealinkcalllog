<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Auth
{
    // ── Session bootstrap ──────────────────────────────────────────────────

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure'   => $secure,
        ]);
    }

    // ── Current-user helpers ───────────────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['user_role'] ?? '') === 'admin';
    }

    /** Returns the logged-in user as an array, or null if not logged in. */
    public static function currentUser(): ?array
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        return [
            'id'       => $_SESSION['user_id'],
            'username' => $_SESSION['user_username'],
            'role'     => $_SESSION['user_role'],
            'ext'      => $_SESSION['user_ext'] ?? null,
        ];
    }

    // ── Access guards ──────────────────────────────────────────────────────

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /login');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden\n";
            exit;
        }
    }

    // ── Login / logout ─────────────────────────────────────────────────────

    public static function login(PDO $db, string $username, string $password): bool
    {
        $stmt = $db->prepare(
            "SELECT id, username, password, role, ext FROM users WHERE username = :u LIMIT 1"
        );
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string) $user['password'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user_username'] = $user['username'];
        $_SESSION['user_role']     = $user['role'];
        $_SESSION['user_ext']      = $user['ext'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }

        session_destroy();
    }

    // ── Admin existence check ──────────────────────────────────────────────

    public static function adminExists(PDO $db): bool
    {
        return (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0;
    }

    // ── HTML pages ─────────────────────────────────────────────────────────

    public static function renderLogin(string $error = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log — Login</title>
  <style>
    *{box-sizing:border-box}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f0f2f5;
         display:flex;align-items:center;justify-content:center;min-height:100vh}
    .box{background:#fff;border-radius:8px;padding:32px 40px;
         box-shadow:0 2px 12px rgba(0,0,0,.1);width:320px}
    h1{font-size:20px;margin:0 0 20px}
    label{display:block;font-size:14px;margin-bottom:4px;color:#444}
    input[type=text],input[type=password]{width:100%;padding:8px 10px;border:1px solid #ccc;
      border-radius:4px;font-size:14px;margin-bottom:14px}
    button{width:100%;padding:9px;background:#4a90d9;color:#fff;border:none;
           border-radius:4px;font-size:14px;cursor:pointer}
    button:hover{background:#357abd}
    .error{color:#c0392b;font-size:13px;margin-bottom:12px}
  </style>
</head>
<body>
  <div class="box">
    <h1>Yealink Call Log</h1>
    <?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="/login">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autocomplete="username" required />
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required />
      <button type="submit">Login</button>
    </form>
  </div>
</body>
</html>
        <?php
    }

    public static function renderInit(string $error = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log — Initial Setup</title>
  <style>
    *{box-sizing:border-box}
    body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f0f2f5;
         display:flex;align-items:center;justify-content:center;min-height:100vh}
    .box{background:#fff;border-radius:8px;padding:32px 40px;
         box-shadow:0 2px 12px rgba(0,0,0,.1);width:380px}
    h1{font-size:20px;margin:0 0 6px}
    p.sub{font-size:13px;color:#555;margin:0 0 20px}
    label{display:block;font-size:14px;margin-bottom:4px;color:#444}
    input[type=text],input[type=password]{width:100%;padding:8px 10px;border:1px solid #ccc;
      border-radius:4px;font-size:14px;margin-bottom:14px}
    button{width:100%;padding:9px;background:#27ae60;color:#fff;border:none;
           border-radius:4px;font-size:14px;cursor:pointer}
    button:hover{background:#219a52}
    .error{color:#c0392b;font-size:13px;margin-bottom:12px}
  </style>
</head>
<body>
  <div class="box">
    <h1>Initial Setup</h1>
    <p class="sub">Create the first administrator account to get started.</p>
    <?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="/init">
      <label for="username">Admin username</label>
      <input type="text" id="username" name="username" autocomplete="username" required />
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required />
      <label for="password2">Confirm password</label>
      <input type="password" id="password2" name="password2" autocomplete="new-password" required />
      <button type="submit">Create Admin Account</button>
    </form>
  </div>
</body>
</html>
        <?php
    }

    public static function renderAdminUsers(PDO $db, string $error = '', string $success = ''): void
    {
        $stmt  = $db->query("SELECT id, username, role, ext, created_at FROM users ORDER BY id ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log — User Management</title>
  <style>
    body{font-family:system-ui,Arial,sans-serif;margin:24px;color:#222}
    h1{margin-bottom:16px}
    h2{margin:28px 0 12px;font-size:17px}
    table{border-collapse:collapse;width:100%;max-width:700px;margin-bottom:28px}
    th,td{border:1px solid #ddd;padding:8px 12px;text-align:left;font-size:14px}
    th{background:#f5f5f5;font-weight:600}
    tr:nth-child(even){background:#fafafa}
    .form-row{margin-bottom:12px}
    label{display:block;font-size:14px;margin-bottom:4px;color:#444}
    input[type=text],input[type=password],select{padding:7px 9px;border:1px solid #ccc;
      border-radius:4px;font-size:14px;width:260px}
    button{padding:8px 18px;background:#4a90d9;color:#fff;border:none;
           border-radius:4px;font-size:14px;cursor:pointer}
    button:hover{background:#357abd}
    .error{color:#c0392b;font-size:13px;margin-bottom:12px}
    .success{color:#27ae60;font-size:13px;margin-bottom:12px}
    .nav{margin-bottom:20px;font-size:14px}
    .nav a{color:#4a90d9;text-decoration:none;margin-right:14px}
    .nav a:hover{text-decoration:underline}
    .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600}
    .badge-admin{background:#d5e8f5;color:#1a6496}
    .badge-user{background:#e9f5e9;color:#2d7a2d}
  </style>
</head>
<body>
  <div class="nav">
    <a href="/dashboard">← Dashboard</a>
    <a href="/calls">Calls</a>
    <a href="/logout">Logout</a>
  </div>
  <h1>User Management</h1>

  <?php if ($error !== ''): ?>
  <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($success !== ''): ?>
  <div class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <h2>Users</h2>
  <table>
    <thead>
      <tr><th>ID</th><th>Username</th><th>Role</th><th>Extension</th><th>Created</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int) $u['id'] ?></td>
        <td><?= htmlspecialchars((string) $u['username'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><span class="badge badge-<?= htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars((string) $u['role'], ENT_QUOTES, 'UTF-8') ?>
            </span></td>
        <td><?= htmlspecialchars((string) ($u['ext'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) $u['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Create User</h2>
  <form method="post" action="/admin/users">
    <div class="form-row">
      <label for="new_username">Username</label>
      <input type="text" id="new_username" name="username" required />
    </div>
    <div class="form-row">
      <label for="new_password">Password (min 8 characters)</label>
      <input type="password" id="new_password" name="password" required />
    </div>
    <div class="form-row">
      <label for="new_role">Role</label>
      <select id="new_role" name="role">
        <option value="user">User (restricted to one extension)</option>
        <option value="admin">Admin (full access)</option>
      </select>
    </div>
    <div class="form-row" id="ext-row" hidden>
      <label for="new_ext">Extension <small>(required for user role)</small></label>
      <input type="text" id="new_ext" name="ext" />
    </div>
    <button type="submit">Create User</button>
  </form>

  <script>
    var roleEl = document.getElementById('new_role');
    var extRow = document.getElementById('ext-row');
    function syncExtRow() {
      if (roleEl.value === 'admin') {
        extRow.setAttribute('hidden', '');
      } else {
        extRow.removeAttribute('hidden');
      }
    }
    roleEl.addEventListener('change', syncExtRow);
    syncExtRow();
  </script>
</body>
</html>
        <?php
    }
}

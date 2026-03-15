<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_secure'   => $secure,
        ]);
    }

    public static function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
    public static function isAdmin(): bool { return ($_SESSION['user_role'] ?? '') === 'admin'; }
    public static function isTeamLead(): bool { return ($_SESSION['user_role'] ?? '') === 'team_lead'; }

    public static function currentUser(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return [
            'id'       => (int)($_SESSION['user_id'] ?? 0),
            'username' => (string)($_SESSION['user_username'] ?? ''),
            'role'     => (string)($_SESSION['user_role'] ?? ''),
            'ext'      => $_SESSION['user_ext'] ?? null,
        ];
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) { header('Location: /login'); exit; }
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

    public static function requireTeamLeadOrAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin() && !self::isTeamLead()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden\n";
            exit;
        }
    }

    /**
     * Returns allowed extensions for reporting.
     * - admin: null (= all)
     * - user: [own ext] (if set)
     * - team_lead: list from team_lead_exts table
     */
    public static function allowedExtensions(PDO $db, array $user): ?array
    {
        if (($user['role'] ?? '') === 'admin') {
            return null;
        }

        if (($user['role'] ?? '') === 'team_lead') {
            $stmt = $db->prepare("SELECT ext FROM team_lead_exts WHERE team_lead_user_id = :id ORDER BY ext ASC");
            $stmt->execute([':id' => (int)($user['id'] ?? 0)]);
            $exts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $e = trim((string)($r['ext'] ?? ''));
                if ($e !== '') $exts[] = $e;
            }
            return $exts; // can be empty => no access to any extension data
        }

        $ext = isset($user['ext']) ? trim((string)$user['ext']) : '';
        if ($ext === '') {
            return []; // no ext => no data
        }
        return [$ext];
    }

    public static function login(PDO $db, string $username, string $password): bool
    {
        $stmt = $db->prepare("SELECT id, username, password_hash, role, ext FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) return false;

        session_regenerate_id(true);
        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['user_username'] = (string)$user['username'];
        $_SESSION['user_role']     = (string)$user['role'];
        $_SESSION['user_ext']      = $user['ext'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    public static function adminExists(PDO $db): bool
    {
        return (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
    }

    public static function adminCount(PDO $db): int
    {
        return (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
    }

    public static function createUser(PDO $db, string $username, string $password, string $role, ?string $ext): void
    {
        $username = trim($username);
        if ($username === '') throw new \RuntimeException('Username is required.');
        if (strlen($password) < 8) throw new \RuntimeException('Password must be at least 8 characters.');

        // NEW: team_lead role
        if ($role !== 'admin' && $role !== 'user' && $role !== 'team_lead') {
            throw new \RuntimeException('Invalid role.');
        }

        if ($role === 'user') {
            $ext = trim((string)$ext);
            if ($ext === '') throw new \RuntimeException('Extension is required for user role.');
        } else {
            // team_lead + admin do not have a single extension
            $ext = null;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) throw new \RuntimeException('Password hashing failed.');

        $stmt = $db->prepare("INSERT INTO users (username, password_hash, role, ext, created_at) VALUES (:u,:p,:r,:e,NOW(3))");
        $stmt->execute([':u'=>$username, ':p'=>$hash, ':r'=>$role, ':e'=>$ext]);
    }

    public static function setPassword(PDO $db, int $userId, string $newPassword): void
    {
        if ($userId <= 0) throw new \RuntimeException('Invalid user id.');
        if (strlen($newPassword) < 8) throw new \RuntimeException('Password must be at least 8 characters.');
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($hash === false) throw new \RuntimeException('Password hashing failed.');

        $stmt = $db->prepare("UPDATE users SET password_hash = :p WHERE id = :id");
        $stmt->execute([':p'=>$hash, ':id'=>$userId]);
        if ($stmt->rowCount() < 1) throw new \RuntimeException('User not found (or password unchanged).');
    }

    public static function deleteUser(PDO $db, int $userId, int $currentUserId): void
    {
        if ($userId <= 0) throw new \RuntimeException('Invalid user id.');
        if ($userId === $currentUserId) throw new \RuntimeException('You cannot delete your own account while logged in.');

        $stmt = $db->prepare("SELECT id, role FROM users WHERE id = :id");
        $stmt->execute([':id'=>$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('User not found.');

        if ((string)$row['role'] === 'admin' && self::adminCount($db) <= 1) {
            throw new \RuntimeException('Cannot delete the last admin account.');
        }

        $del = $db->prepare("DELETE FROM users WHERE id = :id");
        $del->execute([':id'=>$userId]);
    }

    public static function setTeamLeadExtensions(PDO $db, int $teamLeadUserId, array $exts): void
    {
        if ($teamLeadUserId <= 0) throw new \RuntimeException('Invalid user id.');

        // verify role
        $stmt = $db->prepare("SELECT role FROM users WHERE id = :id");
        $stmt->execute([':id' => $teamLeadUserId]);
        $role = (string)($stmt->fetchColumn() ?: '');
        if ($role !== 'team_lead') throw new \RuntimeException('Selected user is not a team lead.');

        // normalize input
        $clean = [];
        foreach ($exts as $e) {
            $e = trim((string)$e);
            if ($e === '') continue;
            if (strlen($e) > 32) $e = substr($e, 0, 32);
            $clean[] = $e;
        }
        $clean = array_values(array_unique($clean));

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM team_lead_exts WHERE team_lead_user_id = :id")
               ->execute([':id' => $teamLeadUserId]);

            if ($clean) {
                $ins = $db->prepare("INSERT INTO team_lead_exts (team_lead_user_id, ext) VALUES (:id, :ext)");
                foreach ($clean as $e) {
                    $ins->execute([':id' => $teamLeadUserId, ':ext' => $e]);
                }
            }

            $db->commit();
        } catch (\Throwable $t) {
            $db->rollBack();
            throw $t;
        }
    }

    public static function renderLogin(string $error = ''): void
    {
        Theme::header('Login', null, '');
        ?>
<div class="px-card" style="max-width:420px;margin:0 auto">
  <h1 class="px-title">Yealink Call Log</h1>
  <p class="px-sub">Sign in to continue.</p>

  <?php if ($error !== ''): ?>
    <div class="px-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" action="/login" class="px-field">
    <label>Username</label>
    <input class="px-input" type="text" name="username" autocomplete="username" required />
    <label>Password</label>
    <input class="px-input" type="password" name="password" autocomplete="current-password" required />
    <div style="margin-top:10px" class="px-actions">
      <button class="px-btn" type="submit">Login</button>
      <a class="px-btn secondary" href="/init">Initial setup</a>
    </div>
  </form>
</div>
<?php
        Theme::footer();
    }

    public static function renderInit(string $error = ''): void
    {
        Theme::header('Initial setup', null, '');
        ?>
<div class="px-card" style="max-width:520px;margin:0 auto">
  <h1 class="px-title">Initial Setup</h1>
  <p class="px-sub">Create the first administrator account.</p>

  <?php if ($error !== ''): ?>
    <div class="px-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="post" action="/init" class="px-row">
    <div class="px-field" style="flex:1;min-width:220px">
      <label>Admin username</label>
      <input class="px-input" type="text" name="username" autocomplete="username" required />
    </div>
    <div class="px-field" style="flex:1;min-width:220px">
      <label>Password</label>
      <input class="px-input" type="password" name="password" autocomplete="new-password" required />
    </div>
    <div class="px-field" style="flex:1;min-width:220px">
      <label>Confirm password</label>
      <input class="px-input" type="password" name="password2" autocomplete="new-password" required />
    </div>
    <div class="px-actions" style="width:100%;margin-top:6px">
      <button class="px-btn" type="submit">Create admin</button>
      <a class="px-btn secondary" href="/login">Back to login</a>
    </div>
  </form>
</div>
<?php
        Theme::footer();
    }

    public static function renderAdminUsers(PDO $db, string $error = '', string $success = ''): void
    {
        $user = self::currentUser();
        Theme::header('User management', $user, 'users');

        $stmt  = $db->query("SELECT id, username, role, ext, created_at FROM users ORDER BY id ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch current team lead assignments
        $teamLeadExts = [];
        $te = $db->query("SELECT team_lead_user_id, ext FROM team_lead_exts ORDER BY team_lead_user_id ASC, ext ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($te as $r) {
            $uid = (int)($r['team_lead_user_id'] ?? 0);
            $ext = (string)($r['ext'] ?? '');
            if ($uid > 0 && $ext !== '') {
                $teamLeadExts[$uid] ??= [];
                $teamLeadExts[$uid][] = $ext;
            }
        }
        ?>
<div class="px-card">
  <h1 class="px-title">User management</h1>

  <?php if ($error !== ''): ?><div class="px-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($success !== ''): ?><div class="px-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <table class="px-table">
    <thead>
      <tr><th>ID</th><th>Username</th><th>Role</th><th>Extension</th><th>Created</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <?php
        $uid = (int)$u['id'];
        $role = (string)($u['role'] ?? '');
        $assigned = ($role === 'team_lead') ? implode(',', $teamLeadExts[$uid] ?? []) : '';
      ?>
      <tr>
        <td><?= $uid ?></td>
        <td><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)($u['ext'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)$u['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <div class="px-actions" style="flex-wrap:wrap">
            <form method="post" action="/admin/users/password" class="px-actions">
              <input type="hidden" name="id" value="<?= $uid ?>" />
              <input class="px-input" style="width:180px" type="password" name="password" placeholder="new password" required />
              <button class="px-btn secondary" type="submit">Set password</button>
            </form>

            <form method="post" action="/admin/users/delete" onsubmit="return confirm('Delete this account?');">
              <input type="hidden" name="id" value="<?= $uid ?>" />
              <button class="px-btn danger" type="submit">Delete</button>
            </form>
          </div>

          <?php if ($role === 'team_lead'): ?>
            <div style="margin-top:10px">
              <form method="post" action="/admin/users/teamlead-exts" class="px-row" style="align-items:flex-end">
                <input type="hidden" name="id" value="<?= $uid ?>" />
                <div class="px-field" style="min-width:320px;flex:1">
                  <label>Allowed extensions (comma separated)</label>
                  <input class="px-input" type="text" name="exts" value="<?= htmlspecialchars($assigned, ENT_QUOTES, 'UTF-8') ?>" placeholder="101,102,103" />
                </div>
                <div class="px-actions">
                  <button class="px-btn secondary" type="submit">Save</button>
                </div>
              </form>
              <div class="px-muted">This team lead will only see these extensions in Dashboard/Calls.</div>
            </div>
          <?php else: ?>
            <div class="px-muted">You can’t delete yourself or the last admin.</div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="height:16px"></div>

<div class="px-card">
  <h2 style="margin:0 0 12px">Create user</h2>
  <form method="post" action="/admin/users" class="px-row">
    <div class="px-field" style="min-width:220px">
      <label>Username</label>
      <input class="px-input" type="text" name="username" required />
    </div>
    <div class="px-field" style="min-width:220px">
      <label>Password (min 8 chars)</label>
      <input class="px-input" type="password" name="password" required />
    </div>
    <div class="px-field" style="min-width:220px">
      <label>Role</label>
      <select class="px-select" id="new_role" name="role">
        <option value="user">User (1 extension)</option>
        <option value="team_lead">Team lead (multiple extensions)</option>
        <option value="admin">Admin (full access)</option>
      </select>
    </div>
    <div class="px-field" style="min-width:220px" id="ext-row" hidden>
      <label>Extension (required for user)</label>
      <input class="px-input" type="text" name="ext" />
    </div>
    <div class="px-actions" style="width:100%">
      <button class="px-btn" type="submit">Create</button>
    </div>
  </form>

  <script>
    var roleEl = document.getElementById('new_role');
    var extRow = document.getElementById('ext-row');
    function syncExtRow(){ roleEl.value === 'user' ? extRow.removeAttribute('hidden') : extRow.setAttribute('hidden',''); }
    roleEl.addEventListener('change', syncExtRow); syncExtRow();
  </script>
</div>
<?php
        Theme::footer();
    }
}
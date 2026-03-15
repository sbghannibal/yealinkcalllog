<?php
declare(strict_types=1);

namespace YealinkCallLog;

final class Theme
{
    public static function header(string $title, ?array $user, string $active = ''): void
    {
        $username = htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role = htmlspecialchars((string)($user['role'] ?? ''), ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="/assets/proximus.css" />
</head>
<body>
  <div class="px-layout">
    <aside class="px-sidebar">
      <div class="px-brand">P</div>
      <a class="px-sidebtn <?= $active==='dashboard'?'active':'' ?>" href="/dashboard" title="Dashboard">▦</a>
      <a class="px-sidebtn <?= $active==='calls'?'active':'' ?>" href="/calls" title="Calls">☎</a>
      <a class="px-sidebtn <?= $active==='month'?'active':'' ?>" href="/month" title="Monthly registration">📅</a>
      <?php if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'team_lead'): ?>
        <a class="px-sidebtn <?= $active==='phonebook'?'active':'' ?>" href="/phonebook" title="Phonebook">📒</a>
      <?php endif; ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <a class="px-sidebtn <?= $active==='users'?'active':'' ?>" href="/admin/users" title="Users">👤</a>
        <a class="px-sidebtn <?= $active==='setup'?'active':'' ?>" href="/setup" title="Setup">⚙</a>
      <?php endif; ?>
      <div class="px-grow"></div>
      <a class="px-sidebtn" href="/logout" title="Logout">⎋</a>
    </aside>

    <main class="px-main">
      <header class="px-topbar">
        <div class="px-breadcrumb">Home / <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="px-right">
          <div class="px-user">
            <b><?= $username !== '' ? $username : '—' ?></b>
            <span><?= $role !== '' ? $role : '—' ?></span>
          </div>
        </div>
      </header>

      <div class="px-content">
<?php
    }

    public static function footer(): void
    {
        ?>
      </div>
    </main>
  </div>
</body>
</html>
<?php
    }
}
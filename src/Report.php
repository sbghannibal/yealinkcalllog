<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Report
{
    // ── Nav bar ────────────────────────────────────────────────────────────

    private static function nav(array $user): string
    {
        $u    = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
        $role = $user['role'] === 'admin' ? ' <small>(admin)</small>' : '';
        $mgmt = $user['role'] === 'admin'
            ? '<a href="/admin/users">Users</a>' : '';
        $setup = $user['role'] === 'admin'
            ? '<a href="/setup">Setup</a>' : '';

        return <<<HTML
<nav>
  <a href="/dashboard">Dashboard</a>
  <a href="/calls">Calls</a>
  {$mgmt}
  {$setup}
  <span class="nav-user">{$u}{$role}</span>
  <a href="/logout">Logout</a>
</nav>
HTML;
    }

    private static function navCss(): string
    {
        return <<<CSS
nav{display:flex;gap:14px;align-items:center;background:#fff;border-bottom:1px solid #e0e0e0;
    padding:10px 24px;margin:-24px -24px 24px;font-size:14px}
nav a{color:#4a90d9;text-decoration:none}
nav a:hover{text-decoration:underline}
.nav-user{margin-left:auto;color:#555}
CSS;
    }

    // ── Dashboard ──────────────────────────────────────────────────────────

    public static function renderDashboard(PDO $db, Config $cfg, array $user, array $q): void
    {
        $tz    = new DateTimeZone('UTC');
        $now   = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');

        // Parse and validate date input.
        $day = isset($q['date']) ? (string) $q['date'] : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = $today;
        }

        // Enforce 12-month hard limit.
        $min = $now->modify('-12 months');
        try {
            $start = new DateTimeImmutable($day . ' 00:00:00', $tz);
        } catch (\Exception $e) {
            $start = new DateTimeImmutable($today . ' 00:00:00', $tz);
        }

        if ($start < $min) {
            $start = new DateTimeImmutable($min->format('Y-m-d') . ' 00:00:00', $tz);
            $day   = $start->format('Y-m-d');
        }

        $end = $start->modify('+1 day');

        // Restrict non-admin users to their own extension.
        $extFilter = ($user['role'] !== 'admin' && $user['ext'] !== null)
            ? (string) $user['ext']
            : null;

        // NOTE: "Answered" on the dashboard is INCOMING answered only:
        // ringing_at IS NOT NULL AND answered_at IS NOT NULL
        $sql = "
            SELECT
                ext,
                SUM(CASE WHEN direction = 'in'  THEN 1 ELSE 0 END) AS incoming_calls,
                SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_calls,

                SUM(received) AS received_calls,

                SUM(CASE
                    WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0
                END) AS answered_calls,

                SUM(missed)   AS missed_calls,

                ROUND(
                    100 * SUM(CASE WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0 END)
                    / NULLIF(SUM(received), 0),
                    1
                ) AS answer_rate_pct,

                SUM(
                    CASE
                        WHEN answered_at IS NOT NULL
                             AND ended_at IS NOT NULL
                             AND ended_at >= answered_at
                        THEN TIMESTAMPDIFF(SECOND, answered_at, ended_at)
                        ELSE 0
                    END
                ) AS total_talk_secs
            FROM yealink_calls
            WHERE first_seen_at >= :start
              AND first_seen_at <  :end";

        if ($extFilter !== null) {
            $sql .= " AND ext = :ext";
        }

        $sql .= "
            GROUP BY ext
            ORDER BY received_calls DESC, ext ASC";

        $stmt = $db->prepare($sql);
        $params = [
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end'   => $end->format('Y-m-d H:i:s'),
        ];
        if ($extFilter !== null) {
            $params[':ext'] = $extFilter;
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $clamped = ($day !== (isset($q['date']) ? $q['date'] : $today));

        header('Content-Type: text/html; charset=utf-8');
        $nav = self::nav($user);
        $navCss = self::navCss();
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log Dashboard</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 24px; color: #222; }
    h1   { margin-bottom: 8px; }
    form { margin-bottom: 12px; }
    table { border-collapse: collapse; width: 100%; max-width: 980px; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th { background: #f5f5f5; font-weight: 600; }
    tr:nth-child(even) { background: #fafafa; }
    .notice { color: #b06000; font-size: 13px; margin-bottom: 8px; }
    .muted  { color: #888; font-size: 12px; margin-top: 8px; }
    a { color: #4a90d9; text-decoration: none; }
    a:hover { text-decoration: underline; }
    <?= $navCss ?>
  </style>
</head>
<body>
  <?= $nav ?>
  <h1>Yealink Call Log &mdash; Dashboard</h1>

  <form method="get" action="/dashboard">
    <label>Date:
      <input type="date" name="date"
             value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>"
             max="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" />
    </label>
    <button type="submit">View</button>
  </form>

  <?php if ($clamped): ?>
  <p class="notice">&#9888; The requested date is older than 12 months. Showing data from <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?> instead.</p>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Extension</th>
        <th>Incoming</th>
        <th>Outgoing</th>
        <th>Answered (incoming)</th>
        <th>Missed</th>
        <th>Answer Rate %</th>
        <th>Total Talk Time</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <a href="/extension?ext=<?= urlencode((string) $r['ext']) ?>">
              <?= htmlspecialchars((string) $r['ext'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <td><?= (int) $r['incoming_calls'] ?></td>
          <td><?= (int) $r['outgoing_calls'] ?></td>
          <td><?= (int) $r['answered_calls'] ?></td>
          <td><?= (int) $r['missed_calls'] ?></td>
          <td><?= htmlspecialchars((string) ($r['answer_rate_pct'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= self::formatDurationHhMmSs((int) $r['total_talk_secs']) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="7" class="muted">No calls recorded for <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <p class="muted">Reporting is limited to the last 12 months.</p>
</body>
</html>
        <?php
    }

    // ── Extension stats ────────────────────────────────────────────────────

    public static function renderExtensionStats(PDO $db, Config $cfg, array $user, array $q): void
    {
        $tz  = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);

        // Non-admin users are always restricted to their own extension.
        if ($user['role'] !== 'admin' && $user['ext'] !== null) {
            $ext = (string) $user['ext'];
        } else {
            $ext = trim((string) ($q['ext'] ?? ''));
        }

        if ($ext === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Missing required parameter: ext\n";
            return;
        }

        // Today: calendar day (same as dashboard). Others: rolling windows.
        $todayStart = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', $tz);
        $todayEnd   = $todayStart->modify('+1 day');

        $windows = [
            ['label' => 'Today',          'start' => $todayStart,            'end' => $todayEnd],
            ['label' => 'Last 7 days',    'start' => $now->modify('-7 days'),'end' => $now],
            ['label' => 'Last 1 month',   'start' => $now->modify('-1 month'),'end' => $now],
            ['label' => 'Last 3 months',  'start' => $now->modify('-3 months'),'end' => $now],
            ['label' => 'Last 6 months',  'start' => $now->modify('-6 months'),'end' => $now],
            ['label' => 'Last 12 months', 'start' => $now->modify('-12 months'),'end' => $now],
        ];

        // Same meaning as dashboard:
        // - Incoming/Outgoing by direction
        // - Answered means incoming answered only
        // - Missed = missed flag
        // - Talk time from answered_at..ended_at
        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN direction = 'in'  THEN 1 ELSE 0 END) AS incoming_calls,
                SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_calls,

                SUM(received) AS received_calls,

                SUM(CASE
                    WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0
                END) AS answered_calls,

                SUM(missed) AS missed_calls,

                ROUND(
                    100 * SUM(CASE WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0 END)
                    / NULLIF(SUM(received), 0),
                    1
                ) AS answer_rate_pct,

                SUM(
                    CASE
                        WHEN answered_at IS NOT NULL
                             AND ended_at IS NOT NULL
                             AND ended_at >= answered_at
                        THEN TIMESTAMPDIFF(SECOND, answered_at, ended_at)
                        ELSE 0
                    END
                ) AS total_talk_secs
            FROM yealink_calls
            WHERE ext = :ext
              AND first_seen_at >= :start
              AND first_seen_at <  :end
        ");

        $rows = [];
        foreach ($windows as $w) {
            $stmt->execute([
                ':ext'   => $ext,
                ':start' => $w['start']->format('Y-m-d H:i:s'),
                ':end'   => $w['end']->format('Y-m-d H:i:s'),
            ]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $rows[] = [
                'label'          => $w['label'],
                'incoming_calls' => (int) ($r['incoming_calls'] ?? 0),
                'outgoing_calls' => (int) ($r['outgoing_calls'] ?? 0),
                'answered_calls' => (int) ($r['answered_calls'] ?? 0),
                'missed_calls'   => (int) ($r['missed_calls'] ?? 0),
                'answer_rate_pct'=> $r['answer_rate_pct'],
                'talk_secs'      => (int) ($r['total_talk_secs'] ?? 0),
            ];
        }

        header('Content-Type: text/html; charset=utf-8');
        $nav = self::nav($user);
        $navCss = self::navCss();
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log — Extension <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 24px; color: #222; }
    h1   { margin-bottom: 6px; }
    .muted { color: #666; font-size: 13px; margin-bottom: 14px; }
    table { border-collapse: collapse; width: 100%; max-width: 980px; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th { background: #f5f5f5; font-weight: 600; }
    tr:nth-child(even) { background: #fafafa; }
    <?= $navCss ?>
  </style>
</head>
<body>
  <?= $nav ?>
  <h1>Extension <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?></h1>
  <div class="muted">
    Rolling windows (except Today which is the current UTC day).
  </div>

  <table>
    <thead>
      <tr>
        <th>Window</th>
        <th>Incoming</th>
        <th>Outgoing</th>
        <th>Answered (incoming)</th>
        <th>Missed</th>
        <th>Answer Rate %</th>
        <th>Total Talk Time (HH:MM:SS)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int) $r['incoming_calls'] ?></td>
        <td><?= (int) $r['outgoing_calls'] ?></td>
        <td><?= (int) $r['answered_calls'] ?></td>
        <td><?= (int) $r['missed_calls'] ?></td>
        <td><?= htmlspecialchars((string) ($r['answer_rate_pct'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= self::formatDurationHhMmSs((int) $r['talk_secs']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

</body>
</html>
        <?php
    }

    // ── Calls listing ──────────────────────────────────────────────────────

    public static function renderCalls(PDO $db, Config $cfg, array $user, array $q): void
    {
        $tz  = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);

        // ── Date range ─────────────────────────────────────────────────────
        $todayStr = $now->format('Y-m-d');
        $minDate  = $now->modify('-12 months');

        // Parse from/to; default to last 7 days.
        $fromStr = isset($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['from'])
            ? (string) $q['from'] : $now->modify('-7 days')->format('Y-m-d');
        $toStr   = isset($q['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q['to'])
            ? (string) $q['to']   : $todayStr;

        // Clamp to 12-month window.
        try {
            $fromDt = new DateTimeImmutable($fromStr . ' 00:00:00', $tz);
            $toDt   = new DateTimeImmutable($toStr   . ' 00:00:00', $tz);
        } catch (\Exception $e) {
            $fromDt = $now->modify('-7 days');
            $toDt   = $now;
        }
        if ($fromDt < $minDate) {
            $fromDt  = new DateTimeImmutable($minDate->format('Y-m-d') . ' 00:00:00', $tz);
            $fromStr = $fromDt->format('Y-m-d');
        }
        if ($toDt > $now) {
            $toDt  = $now;
            $toStr = $todayStr;
        }
        if ($fromDt > $toDt) {
            $fromDt  = $toDt;
            $fromStr = $toStr;
        }

        $rangeStart = $fromDt;
        $rangeEnd   = $toDt->modify('+1 day'); // exclusive upper bound

        // ── Extension filter ───────────────────────────────────────────────
        // Non-admin users are always restricted to their own extension.
        if ($user['role'] !== 'admin') {
            $extFilter = (string) ($user['ext'] ?? '');
        } else {
            $extFilter = isset($q['ext']) && trim((string) $q['ext']) !== ''
                ? trim((string) $q['ext'])
                : null;
        }

        // ── Pagination ─────────────────────────────────────────────────────
        $perPage = 50;
        $page    = max(1, (int) ($q['page'] ?? 1));

        // Build WHERE clause.
        $where  = "first_seen_at >= :start AND first_seen_at < :end";
        $params = [':start' => $rangeStart->format('Y-m-d H:i:s'), ':end' => $rangeEnd->format('Y-m-d H:i:s')];
        if ($extFilter !== null && $extFilter !== '') {
            $where          .= " AND ext = :ext";
            $params[':ext']  = $extFilter;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM yealink_calls WHERE {$where}");
        $countStmt->execute($params);
        $total  = (int) $countStmt->fetchColumn();
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $listStmt = $db->prepare("
            SELECT id, ext, direction,
                   first_seen_at, ringing_at, outgoing_at, answered_at, ended_at, missed_at,
                   local_uri, remote_uri, display_local, display_remote,
                   received, answered, missed,
                   CASE
                       WHEN answered_at IS NOT NULL AND ended_at IS NOT NULL AND ended_at >= answered_at
                       THEN TIMESTAMPDIFF(SECOND, answered_at, ended_at)
                       ELSE NULL
                   END AS duration_secs
            FROM yealink_calls
            WHERE {$where}
            ORDER BY first_seen_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $listStmt->execute($params);
        $calls = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Quick-button URLs ──────────────────────────────────────────────
        $extQ = ($extFilter !== null && $extFilter !== '') ? '&ext=' . urlencode($extFilter) : '';
        $quickBtns = [
            'Today'         => ['from' => $todayStr,                                   'to' => $todayStr],
            'Last 7 days'   => ['from' => $now->modify('-7 days')->format('Y-m-d'),    'to' => $todayStr],
            'Last 1 month'  => ['from' => $now->modify('-1 month')->format('Y-m-d'),   'to' => $todayStr],
            'Last 3 months' => ['from' => $now->modify('-3 months')->format('Y-m-d'),  'to' => $todayStr],
            'Last 6 months' => ['from' => $now->modify('-6 months')->format('Y-m-d'),  'to' => $todayStr],
            'Last 12 months'=> ['from' => $now->modify('-12 months')->format('Y-m-d'), 'to' => $todayStr],
        ];

        $isAdmin = $user['role'] === 'admin';

        header('Content-Type: text/html; charset=utf-8');
        $nav    = self::nav($user);
        $navCss = self::navCss();
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Call Log — Calls</title>
  <style>
    body { font-family: system-ui, Arial, sans-serif; margin: 24px; color: #222; }
    h1   { margin-bottom: 12px; }
    .filters { margin-bottom: 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .quick-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
    .quick-btns a { padding: 5px 10px; background: #eef3fb; border: 1px solid #c5d8f5;
                    border-radius: 4px; font-size: 13px; color: #2a6aad; text-decoration: none; }
    .quick-btns a:hover, .quick-btns a.active { background: #4a90d9; color: #fff; border-color: #4a90d9; }
    label  { font-size: 13px; color: #555; }
    input[type=date], input[type=text] { padding: 5px 8px; border: 1px solid #ccc;
                                         border-radius: 4px; font-size: 13px; }
    button[type=submit] { padding: 6px 14px; background: #4a90d9; color: #fff; border: none;
                          border-radius: 4px; font-size: 13px; cursor: pointer; }
    button[type=submit]:hover { background: #357abd; }
    table { border-collapse: collapse; width: 100%; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 7px 10px; text-align: left; }
    th { background: #f5f5f5; font-weight: 600; white-space: nowrap; }
    tr:nth-child(even) { background: #fafafa; }
    .dir-in  { color: #27ae60; font-weight: 600; }
    .dir-out { color: #2980b9; font-weight: 600; }
    .missed  { color: #c0392b; }
    .muted   { color: #888; font-size: 12px; margin-top: 10px; }
    .pagination { margin-top: 14px; display: flex; gap: 8px; align-items: center; font-size: 13px; }
    .pagination a { color: #4a90d9; text-decoration: none; padding: 4px 10px;
                    border: 1px solid #ccc; border-radius: 4px; }
    .pagination a:hover { background: #eef3fb; }
    .pagination .cur { padding: 4px 10px; background: #4a90d9; color: #fff; border-radius: 4px; }
    <?= $navCss ?>
  </style>
</head>
<body>
  <?= $nav ?>
  <h1>Calls</h1>

  <div class="quick-btns">
    <?php foreach ($quickBtns as $label => $range): ?>
    <?php
      $activeClass = ($fromStr === $range['from'] && $toStr === $range['to']) ? 'active' : '';
      $href = '/calls?from=' . urlencode($range['from']) . '&to=' . urlencode($range['to']) . $extQ;
    ?>
    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"<?= $activeClass ? ' class="active"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="/calls" class="filters">
    <label>From: <input type="date" name="from" value="<?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>"
           min="<?= htmlspecialchars($minDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
           max="<?= htmlspecialchars($todayStr, ENT_QUOTES, 'UTF-8') ?>" /></label>
    <label>To: <input type="date" name="to" value="<?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>"
           min="<?= htmlspecialchars($minDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
           max="<?= htmlspecialchars($todayStr, ENT_QUOTES, 'UTF-8') ?>" /></label>
    <?php if ($isAdmin): ?>
    <label>Extension: <input type="text" name="ext" value="<?= htmlspecialchars((string) ($extFilter ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="all" style="width:100px" /></label>
    <?php endif; ?>
    <button type="submit">Apply</button>
  </form>

  <p class="muted">
    Showing <?= number_format($total) ?> call<?= $total !== 1 ? 's' : '' ?>
    from <?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>
    to <?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>.
    Reporting is limited to the last 12 months.
  </p>

  <table>
    <thead>
      <tr>
        <th>Date / Time (UTC)</th>
        <?php if ($isAdmin && $extFilter === null): ?><th>Ext</th><?php endif; ?>
        <th>Dir</th>
        <th>Remote</th>
        <th>Local</th>
        <th>Answered at</th>
        <th>Ended at</th>
        <th>Missed</th>
        <th>Duration</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($calls): ?>
        <?php foreach ($calls as $c): ?>
        <tr>
          <td><?= htmlspecialchars((string) $c['first_seen_at'], ENT_QUOTES, 'UTF-8') ?></td>
          <?php if ($isAdmin && $extFilter === null): ?>
          <td>
            <a href="/extension?ext=<?= urlencode((string) $c['ext']) ?>">
              <?= htmlspecialchars((string) $c['ext'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <?php endif; ?>
          <td class="dir-<?= htmlspecialchars((string) ($c['direction'] ?? 'in'), ENT_QUOTES, 'UTF-8') ?>">
            <?= ($c['direction'] === 'out') ? '↑ out' : '↓ in' ?>
          </td>
          <td>
            <?php
              $rd = htmlspecialchars((string) ($c['display_remote'] ?? ''), ENT_QUOTES, 'UTF-8');
              $ru = htmlspecialchars((string) ($c['remote_uri'] ?? ''), ENT_QUOTES, 'UTF-8');
              echo $rd !== '' ? $rd : ($ru !== '' ? $ru : '—');
              if ($rd !== '' && $ru !== '') echo ' <small style="color:#888">(' . $ru . ')</small>';
            ?>
          </td>
          <td>
            <?php
              $ld = htmlspecialchars((string) ($c['display_local'] ?? ''), ENT_QUOTES, 'UTF-8');
              $lu = htmlspecialchars((string) ($c['local_uri'] ?? ''), ENT_QUOTES, 'UTF-8');
              echo $ld !== '' ? $ld : ($lu !== '' ? $lu : '—');
              if ($ld !== '' && $lu !== '') echo ' <small style="color:#888">(' . $lu . ')</small>';
            ?>
          </td>
          <td><?= $c['answered_at'] ? htmlspecialchars((string) $c['answered_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
          <td><?= $c['ended_at']    ? htmlspecialchars((string) $c['ended_at'],    ENT_QUOTES, 'UTF-8') : '—' ?></td>
          <td><?= $c['missed'] ? '<span class="missed">✕</span>' : '' ?></td>
          <td><?= $c['duration_secs'] !== null ? self::formatDurationHhMmSs((int) $c['duration_secs']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?= ($isAdmin && $extFilter === null) ? 9 : 8 ?>" class="muted" style="text-align:center">No calls in this period.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?>
    <a href="/calls?from=<?= urlencode($fromStr) ?>&to=<?= urlencode($toStr) ?><?= $extQ ?>&page=<?= $page - 1 ?>">← Prev</a>
    <?php endif; ?>
    <span class="cur">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?>
    <a href="/calls?from=<?= urlencode($fromStr) ?>&to=<?= urlencode($toStr) ?><?= $extQ ?>&page=<?= $page + 1 ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</body>
</html>
        <?php
    }

    private static function formatDurationHhMmSs(int $seconds): string
    {
        if ($seconds <= 0) {
            return '00:00:00';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}

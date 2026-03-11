<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Report
{
    public static function renderDashboard(PDO $db, Config $cfg, array $q): void
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

        // NOTE: "Answered" on the dashboard is INCOMING answered only:
        // ringing_at IS NOT NULL AND answered_at IS NOT NULL
        $stmt = $db->prepare("
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
              AND first_seen_at <  :end
            GROUP BY ext
            ORDER BY received_calls DESC, ext ASC
        ");
        $stmt->execute([
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end'   => $end->format('Y-m-d H:i:s'),
        ]);
        $rows = $stmt->fetchAll();

        $clamped = ($day !== (isset($q['date']) ? $q['date'] : $today));

        header('Content-Type: text/html; charset=utf-8');
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
  </style>
</head>
<body>
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

    public static function renderExtensionStats(PDO $db, Config $cfg, array $q): void
    {
        $tz  = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);

        $ext = trim((string) ($q['ext'] ?? ''));
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
    .nav { margin-top: 14px; font-size: 14px; }
    .nav a { color: #4a90d9; text-decoration: none; margin-right: 14px; }
    .nav a:hover { text-decoration: underline; }
  </style>
</head>
<body>
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

  <div class="nav">
    <a href="/dashboard">← Dashboard</a>
  </div>
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

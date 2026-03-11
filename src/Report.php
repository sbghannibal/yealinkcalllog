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

        $stmt = $db->prepare("
            SELECT
                ext,
                SUM(CASE WHEN direction = 'in'  THEN 1 ELSE 0 END) AS incoming_calls,
                SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_calls,
                SUM(received)  AS received_calls,
                SUM(answered)  AS answered_calls,
                SUM(missed)    AS missed_calls,
                ROUND(100 * SUM(answered) / NULLIF(SUM(received), 0), 1) AS answer_rate_pct,
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
    table { border-collapse: collapse; width: 100%; max-width: 900px; }
    th, td { border: 1px solid #ddd; padding: 8px 12px; text-align: left; }
    th { background: #f5f5f5; font-weight: 600; }
    tr:nth-child(even) { background: #fafafa; }
    .notice { color: #b06000; font-size: 13px; margin-bottom: 8px; }
    .muted  { color: #888; font-size: 12px; margin-top: 8px; }
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
        <th>Answered</th>
        <th>Missed</th>
        <th>Answer Rate %</th>
        <th>Total Talk Time</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($rows): ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars((string) $r['ext'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int) $r['incoming_calls'] ?></td>
          <td><?= (int) $r['outgoing_calls'] ?></td>
          <td><?= (int) $r['answered_calls'] ?></td>
          <td><?= (int) $r['missed_calls'] ?></td>
          <td><?= htmlspecialchars((string) ($r['answer_rate_pct'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= self::formatDuration((int) $r['total_talk_secs']) ?></td>
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

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d h %02d min %02d s', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%d min %02d s', $m, $s);
        }
        return sprintf('%d s', $s);
    }
}

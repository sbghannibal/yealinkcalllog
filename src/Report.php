<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Report
{
    // ── Dashboard ──────────────────────────────────────────────────────────

    public static function renderDashboard(PDO $db, Config $cfg, array $user, array $q): void
    {
        // normalize user array to avoid undefined index + htmlspecialchars(null)
        $userNorm = [
            'id'       => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'role'     => (string)($user['role'] ?? 'user'),
            'ext'      => $user['ext'] ?? null,
        ];
        $userRole = $userNorm['role'];
        $userExt  = $userNorm['ext'];

        $tz    = new DateTimeZone('Europe/Brussels');
        $utcTz = new DateTimeZone('UTC');
        $now   = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');

        $day = isset($q['date']) ? (string)$q['date'] : $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = $today;
        }

        $min = $now->modify('-12 months');
        try {
            $start = new DateTimeImmutable($day . ' 00:00:00', $tz);
        } catch (\Exception) {
            $start = new DateTimeImmutable($today . ' 00:00:00', $tz);
            $day   = $today;
        }

        if ($start < $min) {
            $start = new DateTimeImmutable($min->format('Y-m-d') . ' 00:00:00', $tz);
            $day   = $start->format('Y-m-d');
        }

        $end = $start->modify('+1 day');

        // NEW: role-aware extension filtering (admin = all, user = own, team_lead = list)
        $allowedExts = Auth::allowedExtensions($db, $userNorm); // null=all, []=none, ['101','102']=some

        $sql = "
            SELECT
                ext,
                MAX(phone_model) AS phone_model,
                SUM(CASE WHEN direction = 'in'  THEN 1 ELSE 0 END) AS incoming_calls,
                SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_calls,
                SUM(received) AS received_calls,
                SUM(CASE WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0 END) AS answered_calls,
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
            WHERE first_seen_at >= :start
              AND first_seen_at <  :end";

        $params = [
            ':start' => $start->setTimezone($utcTz)->format('Y-m-d H:i:s'),
            ':end'   => $end->setTimezone($utcTz)->format('Y-m-d H:i:s'),
        ];

        // apply allowed extensions filter
        if (is_array($allowedExts)) {
            if (!$allowedExts) {
                // no allowed extensions => force empty result
                $sql .= " AND 1=0";
            } else {
                $ph = [];
                foreach ($allowedExts as $i => $e) {
                    $k = ':ext' . $i;
                    $ph[] = $k;
                    $params[$k] = $e;
                }
                $sql .= " AND ext IN (" . implode(',', $ph) . ")";
            }
        }

        $sql .= " GROUP BY ext ORDER BY received_calls DESC, ext ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $requested = isset($q['date']) ? (string)$q['date'] : $today;
        $clamped = ($day !== $requested);

        Theme::header('Dashboard', $userNorm, 'dashboard');
        ?>
<div class="px-card">
  <h1 class="px-title">Dashboard</h1>
  <p class="px-sub">Daily overview (Europe/Brussels). Reporting limited to last 12 months.</p>

  <form method="get" action="/dashboard" class="px-row" style="align-items:flex-end">
    <div class="px-field" style="min-width:220px">
      <label>Date</label>
      <input class="px-input" type="date" name="date"
             value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>"
             max="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" />
    </div>
    <div class="px-actions">
      <button class="px-btn" type="submit">View</button>
      <a class="px-btn secondary" href="/extension?ext=<?= urlencode((string)($userExt ?? '')) ?>" style="<?= ($userRole==='admin')?'display:none':'' ?>">My extension</a>
    </div>
  </form>

  <?php if ($clamped): ?>
    <div class="px-error" style="color:#b06000">
      Requested date is older than 12 months. Showing <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?> instead.
    </div>
  <?php endif; ?>

  <table class="px-table" style="margin-top:10px">
    <thead>
      <tr>
        <th>Extension</th>
        <th>Phone type</th>
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
        <?php
          $ext = (string)($r['ext'] ?? '');
          $model = (string)($r['phone_model'] ?? '');
          $modelShow = ($model !== '') ? $model : '—';
        ?>
        <tr>
          <td>
            <a href="/extension?ext=<?= urlencode($ext) ?>">
              <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?>
            </a>
            <?php if ($ext !== ''): ?>
              <a href="<?= htmlspecialchars(self::telLink($ext), ENT_QUOTES, 'UTF-8') ?>"
                 title="Call <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?>"
                 style="margin-left:10px;text-decoration:none">
                📞
              </a>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($modelShow, ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= (int)($r['incoming_calls'] ?? 0) ?></td>
          <td><?= (int)($r['outgoing_calls'] ?? 0) ?></td>
          <td><?= (int)($r['answered_calls'] ?? 0) ?></td>
          <td><?= (int)($r['missed_calls'] ?? 0) ?></td>
          <td><?= htmlspecialchars((string)($r['answer_rate_pct'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= self::formatDurationHhMmSs((int)($r['total_talk_secs'] ?? 0)) ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="8" class="px-muted">No calls recorded for <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>.</td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <p class="px-muted" style="margin-top:10px">
    Phone type is filled when Yealink sends <code>model=...</code> (or <code>phone=...</code>/<code>device=...</code>) to <code>/yealink/event</code>.
  </p>
</div>
<?php
        Theme::footer();
    }

    // ── Extension stats ────────────────────────────────────────────────────

    public static function renderExtensionStats(PDO $db, Config $cfg, array $user, array $q): void
    {
        $userNorm = [
            'id'       => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'role'     => (string)($user['role'] ?? 'user'),
            'ext'      => $user['ext'] ?? null,
        ];
        $allowedExts = Auth::allowedExtensions($db, $userNorm);

        $tz    = new DateTimeZone('Europe/Brussels');
        $utcTz = new DateTimeZone('UTC');
        $now   = new DateTimeImmutable('now', $tz);

        $ext = trim((string)($q['ext'] ?? ''));
        if ($ext === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Missing required parameter: ext\n";
            return;
        }

        // enforce team_lead / user restrictions
        if (is_array($allowedExts) && !in_array($ext, $allowedExts, true)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Forbidden\n";
            return;
        }

        $todayStart = new DateTimeImmutable($now->format('Y-m-d') . ' 00:00:00', $tz);
        $todayEnd   = $todayStart->modify('+1 day');

        $windows = [
            ['label' => 'Today',          'start' => $todayStart,               'end' => $todayEnd],
            ['label' => 'Last 7 days',    'start' => $now->modify('-7 days'),   'end' => $now],
            ['label' => 'Last 1 month',   'start' => $now->modify('-1 month'),  'end' => $now],
            ['label' => 'Last 3 months',  'start' => $now->modify('-3 months'), 'end' => $now],
            ['label' => 'Last 6 months',  'start' => $now->modify('-6 months'), 'end' => $now],
            ['label' => 'Last 12 months', 'start' => $now->modify('-12 months'),'end' => $now],
        ];

        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN direction = 'in'  THEN 1 ELSE 0 END) AS incoming_calls,
                SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS outgoing_calls,
                SUM(received) AS received_calls,
                SUM(CASE WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0 END) AS answered_calls,
                SUM(missed) AS missed_calls,
                ROUND(
                    100 * SUM(CASE WHEN ringing_at IS NOT NULL AND answered_at IS NOT NULL THEN 1 ELSE 0 END)
                    / NULLIF(SUM(received), 0),
                    1
                ) AS answer_rate_pct,
                SUM(
                    CASE
                        WHEN answered_at IS NOT NULL AND ended_at IS NOT NULL AND ended_at >= answered_at
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
                ':start' => $w['start']->setTimezone($utcTz)->format('Y-m-d H:i:s'),
                ':end'   => $w['end']->setTimezone($utcTz)->format('Y-m-d H:i:s'),
            ]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

            $rows[] = [
                'label'           => $w['label'],
                'incoming_calls'  => (int)($r['incoming_calls'] ?? 0),
                'outgoing_calls'  => (int)($r['outgoing_calls'] ?? 0),
                'answered_calls'  => (int)($r['answered_calls'] ?? 0),
                'missed_calls'    => (int)($r['missed_calls'] ?? 0),
                'answer_rate_pct' => $r['answer_rate_pct'],
                'talk_secs'       => (int)($r['total_talk_secs'] ?? 0),
            ];
        }

        Theme::header('Extension', $userNorm, '');
        ?>
<div class="px-card">
  <h1 class="px-title">Extension <?= htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') ?></h1>
  <p class="px-sub">Rolling windows (except Today = current Europe/Brussels day).</p>

  <table class="px-table">
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
        <td><?= htmlspecialchars((string)$r['label'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= (int)$r['incoming_calls'] ?></td>
        <td><?= (int)$r['outgoing_calls'] ?></td>
        <td><?= (int)$r['answered_calls'] ?></td>
        <td><?= (int)$r['missed_calls'] ?></td>
        <td><?= htmlspecialchars((string)($r['answer_rate_pct'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= self::formatDurationHhMmSs((int)$r['talk_secs']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
        Theme::footer();
    }

    // ── Calls listing ──────────────────────────────────────────────────────

    public static function renderCalls(PDO $db, Config $cfg, array $user, array $q): void
    {
        $userNorm = [
            'id'       => (int)($user['id'] ?? 0),
            'username' => (string)($user['username'] ?? ''),
            'role'     => (string)($user['role'] ?? 'user'),
            'ext'      => $user['ext'] ?? null,
        ];
        $allowedExts = Auth::allowedExtensions($db, $userNorm);

        $tz    = new DateTimeZone('Europe/Brussels');
        $utcTz = new DateTimeZone('UTC');
        $now   = new DateTimeImmutable('now', $tz);

        $todayStr = $now->format('Y-m-d');
        $minDate  = $now->modify('-12 months');

        $fromStr = isset($q['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['from'])
            ? (string)$q['from']
            : $now->modify('-7 days')->format('Y-m-d');

        $toStr = isset($q['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$q['to'])
            ? (string)$q['to']
            : $todayStr;

        $searchRaw = isset($q['q']) ? (string)$q['q'] : '';
        $search = trim($searchRaw);
        if (strlen($search) > 64) {
            $search = substr($search, 0, 64);
        }

        try {
            $fromDt = new DateTimeImmutable($fromStr . ' 00:00:00', $tz);
            $toDt   = new DateTimeImmutable($toStr . ' 00:00:00', $tz);
        } catch (\Exception) {
            $fromDt = $now->modify('-7 days');
            $toDt   = $now;
            $fromStr = $fromDt->format('Y-m-d');
            $toStr   = $toDt->format('Y-m-d');
        }

        if ($fromDt < $minDate) {
            $fromDt  = new DateTimeImmutable($minDate->format('Y-m-d') . ' 00:00:00', $tz);
            $fromStr = $fromDt->format('Y-m-d');
        }
        if ($toDt > $now) {
            $toDt  = new DateTimeImmutable($todayStr . ' 00:00:00', $tz);
            $toStr = $todayStr;
        }
        if ($fromDt > $toDt) {
            $fromDt  = $toDt;
            $fromStr = $toStr;
        }

        $rangeStart = $fromDt;
        $rangeEnd   = $toDt->modify('+1 day');

        // admin can optionally filter ext via query, others cannot
        if (($userNorm['role'] ?? '') === 'admin') {
            $extFilter = isset($q['ext']) && trim((string)$q['ext']) !== ''
                ? trim((string)$q['ext'])
                : null;
        } else {
            $extFilter = null;
        }

        $perPage = 50;
        $page    = max(1, (int)($q['page'] ?? 1));

        $where  = "first_seen_at >= :start AND first_seen_at < :end";
        $params = [
            ':start' => $rangeStart->setTimezone($utcTz)->format('Y-m-d H:i:s'),
            ':end'   => $rangeEnd->setTimezone($utcTz)->format('Y-m-d H:i:s'),
        ];

        // apply allowed exts
        if (is_array($allowedExts)) {
            if (!$allowedExts) {
                $where .= " AND 1=0";
            } else {
                $ph = [];
                foreach ($allowedExts as $i => $e) {
                    $k = ':aext' . $i;
                    $ph[] = $k;
                    $params[$k] = $e;
                }
                $where .= " AND ext IN (" . implode(',', $ph) . ")";
            }
        }

        // optional admin ext filter (narrows further)
        if ($extFilter !== null && $extFilter !== '') {
            $where .= " AND ext = :ext";
            $params[':ext'] = $extFilter;
        }

        if ($search !== '') {
            $where .= " AND (
                display_remote LIKE :q
                OR remote_uri LIKE :q
                OR display_local LIKE :q
                OR local_uri LIKE :q
            )";
            $params[':q'] = '%' . $search . '%';
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM yealink_calls WHERE {$where}");
        $countStmt->execute($params);
        $total  = (int)$countStmt->fetchColumn();
        $pages  = max(1, (int)ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $listStmt = $db->prepare("
            SELECT id, ext, direction,
                   first_seen_at, answered_at, ended_at, missed_at,
                   local_uri, remote_uri, display_local, display_remote,
                   missed,
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

        $isAdmin = (($userNorm['role'] ?? '') === 'admin');
        $extQ = ($extFilter !== null && $extFilter !== '') ? '&ext=' . urlencode($extFilter) : '';
        $qQ   = ($search !== '') ? '&q=' . urlencode($search) : '';

        $quickBtns = [
            'Today'          => ['from' => $todayStr,                                     'to' => $todayStr],
            'Last 7 days'    => ['from' => $now->modify('-7 days')->format('Y-m-d'),      'to' => $todayStr],
            'Last 1 month'   => ['from' => $now->modify('-1 month')->format('Y-m-d'),     'to' => $todayStr],
            'Last 3 months'  => ['from' => $now->modify('-3 months')->format('Y-m-d'),    'to' => $todayStr],
            'Last 6 months'  => ['from' => $now->modify('-6 months')->format('Y-m-d'),    'to' => $todayStr],
            'Last 12 months' => ['from' => $now->modify('-12 months')->format('Y-m-d'),   'to' => $todayStr],
        ];

        Theme::header('Calls', $userNorm, 'calls');
        ?>
<div class="px-card">
  <h1 class="px-title">Calls</h1>
  <p class="px-sub">Filter by date range (Europe/Brussels). Hard clamp: last 12 months.</p>

  <div class="px-actions" style="margin-bottom:10px;flex-wrap:wrap">
    <?php foreach ($quickBtns as $label => $range): ?>
      <?php
        $active = ($fromStr === $range['from'] && $toStr === $range['to']);
        $href = '/calls?from=' . urlencode($range['from']) . '&to=' . urlencode($range['to']) . $extQ . $qQ;
      ?>
      <a class="px-btn <?= $active ? '' : 'secondary' ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="/calls" class="px-row" style="align-items:flex-end">
    <div class="px-field" style="min-width:220px">
      <label>From</label>
      <input class="px-input" type="date" name="from" value="<?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>"
             min="<?= htmlspecialchars($minDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
             max="<?= htmlspecialchars($todayStr, ENT_QUOTES, 'UTF-8') ?>" />
    </div>
    <div class="px-field" style="min-width:220px">
      <label>To</label>
      <input class="px-input" type="date" name="to" value="<?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>"
             min="<?= htmlspecialchars($minDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
             max="<?= htmlspecialchars($todayStr, ENT_QUOTES, 'UTF-8') ?>" />
    </div>

    <div class="px-field" style="min-width:260px;flex:1">
      <label>Search number / name</label>
      <input class="px-input" type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="e.g. 0470, +32, John, sip:100" />
    </div>

    <?php if ($isAdmin): ?>
    <div class="px-field" style="min-width:160px">
      <label>Extension (optional)</label>
      <input class="px-input" type="text" name="ext" value="<?= htmlspecialchars((string)($extFilter ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="all" />
    </div>
    <?php endif; ?>

    <div class="px-actions">
      <button class="px-btn" type="submit">Apply</button>
      <?php if ($search !== ''): ?>
        <a class="px-btn secondary" href="/calls?from=<?= urlencode($fromStr) ?>&to=<?= urlencode($toStr) ?><?= $extQ ?>">Clear search</a>
      <?php endif; ?>
    </div>
  </form>

  <p class="px-muted" style="margin-top:10px">
    Showing <?= number_format($total) ?> call<?= $total !== 1 ? 's' : '' ?>
    from <?= htmlspecialchars($fromStr, ENT_QUOTES, 'UTF-8') ?>
    to <?= htmlspecialchars($toStr, ENT_QUOTES, 'UTF-8') ?>.
    <?php if ($search !== ''): ?>
      Search: <b><?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?></b>
    <?php endif; ?>
  </p>

  <table class="px-table" style="margin-top:10px">
    <thead>
      <tr>
        <th>Date / Time (Europe/Brussels)</th>
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
        <?php
          $remoteText = (string)($c['display_remote'] ?? '');
          $remoteUri  = self::maskUriUserPart((string)($c['remote_uri'] ?? ''));
          $localText  = (string)($c['display_local'] ?? '');
          $localUri   = self::maskUriUserPart((string)($c['local_uri'] ?? ''));

          $remoteShow = $remoteText !== '' ? $remoteText : ($remoteUri !== '' ? $remoteUri : '—');
          $localShow  = $localText  !== '' ? $localText  : ($localUri  !== '' ? $localUri  : '—');

          $remoteCall = self::callableNumber($remoteUri, $remoteText, 2);
          $localCall  = self::callableNumber($localUri,  $localText,  2);
        ?>
        <tr>
          <td><?= htmlspecialchars((string)$c['first_seen_at'], ENT_QUOTES, 'UTF-8') ?></td>

          <?php if ($isAdmin && $extFilter === null): ?>
          <td>
            <a href="/extension?ext=<?= urlencode((string)$c['ext']) ?>">
              <?= htmlspecialchars((string)$c['ext'], ENT_QUOTES, 'UTF-8') ?>
            </a>
          </td>
          <?php endif; ?>

          <td><?= (($c['direction'] ?? 'in') === 'out') ? '↑ out' : '↓ in' ?></td>

          <td>
            <?= htmlspecialchars($remoteShow, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($remoteText !== '' && $remoteUri !== ''): ?>
              <span class="px-muted">(<?= htmlspecialchars($remoteUri, ENT_QUOTES, 'UTF-8') ?>)</span>
            <?php endif; ?>
            <?php if ($remoteCall !== null): ?>
              <a href="<?= htmlspecialchars(self::telLink($remoteCall), ENT_QUOTES, 'UTF-8') ?>"
                 title="Call <?= htmlspecialchars($remoteCall, ENT_QUOTES, 'UTF-8') ?>"
                 style="margin-left:8px;text-decoration:none">📞</a>
            <?php endif; ?>
          </td>

          <td>
            <?= htmlspecialchars($localShow, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($localText !== '' && $localUri !== ''): ?>
              <span class="px-muted">(<?= htmlspecialchars($localUri, ENT_QUOTES, 'UTF-8') ?>)</span>
            <?php endif; ?>
            <?php if ($localCall !== null): ?>
              <a href="<?= htmlspecialchars(self::telLink($localCall), ENT_QUOTES, 'UTF-8') ?>"
                 title="Call <?= htmlspecialchars($localCall, ENT_QUOTES, 'UTF-8') ?>"
                 style="margin-left:8px;text-decoration:none">📞</a>
            <?php endif; ?>
          </td>

          <td><?= $c['answered_at'] ? htmlspecialchars((string)$c['answered_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
          <td><?= $c['ended_at'] ? htmlspecialchars((string)$c['ended_at'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
          <td><?= ((int)($c['missed'] ?? 0) === 1) ? '✕' : '' ?></td>
          <td><?= ($c['duration_secs'] !== null) ? self::formatDurationHhMmSs((int)$c['duration_secs']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="<?= ($isAdmin && $extFilter === null) ? 9 : 8 ?>" class="px-muted">No calls in this period.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
        Theme::footer();
    }

    private static function maskUriUserPart(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') return '';
        $pos = strpos($uri, '@');
        if ($pos === false) return $uri;
        return substr($uri, 0, $pos);
    }

    private static function callableNumber(string $uri, string $display, int $minDigits = 2): ?string
    {
        $candidates = [trim($uri), trim($display)];
        foreach ($candidates as $c) {
            if ($c === '') continue;

            $c = preg_replace('/^(sip:|tel:)/i', '', $c) ?? $c;

            if (preg_match('/[a-z]/i', $c)) {
                continue;
            }

            $n = preg_replace('/(?!^\+)[^\d]/', '', $c);
            if (!is_string($n)) continue;

            $digits = preg_replace('/\D/', '', $n);
            if (!is_string($digits) || strlen($digits) < $minDigits) {
                continue;
            }

            if ($digits === '' && $n === '+') {
                continue;
            }

            return $n;
        }
        return null;
    }

    private static function telLink(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return 'tel:';

        $clean = preg_replace('/(?!^\+)[^\d]/', '', $raw);
        if ($clean === null) {
            $clean = $raw;
        }
        if ($clean === '' || $clean === '+') {
            $clean = preg_replace('/[^\d+]/', '', $raw) ?? '';
        }
        return 'tel:' . $clean;
    }

    private static function formatDurationHhMmSs(int $seconds): string
    {
        if ($seconds <= 0) return '00:00:00';
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
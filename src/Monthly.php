<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Monthly
{
    private const TZ = 'Europe/Brussels';

    // ── Main month view ─────────────────────────────────────────────────────

    public static function render(PDO $db, array $user, array $get): void
    {
        Auth::requireLogin();

        $tz  = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('now', $tz);

        // Determine selected month (YYYY-MM), default = current month.
        $monthParam = trim((string)($get['month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = $now->format('Y-m');
        }

        [$year, $mon] = explode('-', $monthParam);
        $year = (int)$year;
        $mon  = (int)$mon;
        if ($year < 2000 || $year > 2100 || $mon < 1 || $mon > 12) {
            $year = (int)$now->format('Y');
            $mon  = (int)$now->format('m');
            $monthParam = sprintf('%04d-%02d', $year, $mon);
        }

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $mon), $tz);
        $end   = $start->modify('+1 month');

        $allowedExts = Auth::allowedExtensions($db, $user);

        // Filter by extension if requested.
        $extFilter = trim((string)($get['ext'] ?? ''));

        // Validate extFilter against allowed
        if ($extFilter !== '') {
            if ($allowedExts !== null && !in_array($extFilter, $allowedExts, true)) {
                $extFilter = '';
            }
        }

        $calls = self::fetchCalls($db, $start, $end, $allowedExts, $extFilter);

        // Gather unique remote URIs for phonebook suggestions.
        $remoteUris = [];
        foreach ($calls as $c) {
            $uri = (string)($c['remote_uri'] ?? '');
            if ($uri !== '') $remoteUris[$uri] = true;
        }
        $suggestions = self::buildSuggestions($db, array_keys($remoteUris));

        // Build list of distinct extensions for filter dropdown.
        $extList = self::fetchExtList($db, $start, $end, $allowedExts);

        $prevMonth = $start->modify('-1 month')->format('Y-m');
        $nextMonth = $start->modify('+1 month')->format('Y-m');
        $maxMonth  = $now->format('Y-m');

        Theme::header('Monthly registration', $user, 'month');
        ?>
<div class="px-card">
  <div class="px-row" style="align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <h1 class="px-title" style="margin:0">Monthly registration</h1>
    <div class="px-row" style="gap:6px;align-items:center">
      <a class="px-btn secondary" href="/month?month=<?= htmlspecialchars($prevMonth, ENT_QUOTES, 'UTF-8') ?><?= $extFilter !== '' ? '&ext=' . urlencode($extFilter) : '' ?>">‹</a>
      <strong><?= htmlspecialchars(self::monthLabel($year, $mon), ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if ($monthParam < $maxMonth): ?>
        <a class="px-btn secondary" href="/month?month=<?= htmlspecialchars($nextMonth, ENT_QUOTES, 'UTF-8') ?><?= $extFilter !== '' ? '&ext=' . urlencode($extFilter) : '' ?>">›</a>
      <?php else: ?>
        <span class="px-btn secondary" style="opacity:.4;cursor:default">›</span>
      <?php endif; ?>
    </div>
    <div class="px-row" style="gap:6px;align-items:center">
      <?php if (count($extList) > 1): ?>
      <form method="get" action="/month" class="px-row" style="gap:4px;margin:0">
        <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam, ENT_QUOTES, 'UTF-8') ?>" />
        <select class="px-select" name="ext" onchange="this.form.submit()">
          <option value="">All extensions</option>
          <?php foreach ($extList as $e): ?>
            <option value="<?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?>" <?= $e === $extFilter ? 'selected' : '' ?>>
              <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <a class="px-btn secondary"
         href="/month/export.csv?month=<?= urlencode($monthParam) ?><?= $extFilter !== '' ? '&ext=' . urlencode($extFilter) : '' ?>">
        ↓ Export CSV
      </a>
    </div>
  </div>
</div>

<div style="height:12px"></div>

<?php if ($calls === []): ?>
<div class="px-card"><p class="px-muted">No calls found for this period.</p></div>
<?php else: ?>
<div class="px-card" style="overflow-x:auto">
  <table class="px-table" style="min-width:900px">
    <thead>
      <tr>
        <th>Date/Time</th>
        <th>Ext</th>
        <th>Dir</th>
        <th>Remote</th>
        <th>Duration</th>
        <th>Case ref</th>
        <th>Contact</th>
        <th>Linked</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($calls as $c): ?>
      <?php
        $callId     = (int)$c['id'];
        $remoteUri  = (string)($c['remote_uri'] ?? '');
        $e164       = Phone::toE164($remoteUri) ?? '';
        $suggestion = $e164 !== '' ? ($suggestions[$e164] ?? null) : null;
        $linkedCase = (string)($c['linked_case_ref'] ?? '');
        $linkedName = (string)($c['linked_contact_name'] ?? '');
        $linkedAt   = (string)($c['linked_at'] ?? '');
        $duration   = self::formatDuration($c);
        $dir        = (string)($c['direction'] ?? '');
        $dirLabel   = $dir === 'out' ? '↑' : ($dir === 'in' ? '↓' : '?');
        $missed     = (bool)($c['missed'] ?? false);
        $ts         = (string)($c['first_seen_at'] ?? '');
      ?>
      <tr>
        <td style="white-space:nowrap"><?= htmlspecialchars($ts, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)($c['ext'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= $dirLabel ?> <?= $missed ? '<span class="px-muted" title="missed">✗</span>' : '' ?></td>
        <td style="font-size:.9em"><?= htmlspecialchars($remoteUri, ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($duration, ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <?php if ($linkedCase !== ''): ?>
            <span style="font-weight:600"><?= htmlspecialchars($linkedCase, ENT_QUOTES, 'UTF-8') ?></span>
          <?php elseif ($suggestion !== null): ?>
            <span class="px-muted" title="suggestion"><?= htmlspecialchars((string)($suggestion['case_ref'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($linkedName !== ''): ?>
            <?= htmlspecialchars($linkedName, ENT_QUOTES, 'UTF-8') ?>
          <?php elseif ($suggestion !== null): ?>
            <span class="px-muted"><?= htmlspecialchars((string)($suggestion['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
          <?php endif; ?>
        </td>
        <td class="px-muted" style="font-size:.85em"><?= $linkedAt !== '' ? htmlspecialchars($linkedAt, ENT_QUOTES, 'UTF-8') : '' ?></td>
        <td>
          <?php if (self::canLink($user, (string)($c['ext'] ?? ''), $allowedExts)): ?>
          <form method="post" action="/month/link" class="px-row" style="gap:4px;flex-wrap:nowrap">
            <input type="hidden" name="call_id" value="<?= $callId ?>" />
            <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam, ENT_QUOTES, 'UTF-8') ?>" />
            <?php if ($extFilter !== ''): ?>
              <input type="hidden" name="ext" value="<?= htmlspecialchars($extFilter, ENT_QUOTES, 'UTF-8') ?>" />
            <?php endif; ?>
            <input type="hidden" name="phone_e164" value="<?= htmlspecialchars($e164, ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="raw_phone" value="<?= htmlspecialchars($remoteUri, ENT_QUOTES, 'UTF-8') ?>" />
            <input
              class="px-input"
              style="width:110px;padding:2px 6px;font-size:.85em"
              type="text"
              name="case_ref"
              placeholder="Dossier"
              value="<?= htmlspecialchars($linkedCase !== '' ? $linkedCase : (string)($suggestion['case_ref'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
              required
            />
            <input
              class="px-input"
              style="width:120px;padding:2px 6px;font-size:.85em"
              type="text"
              name="contact_name"
              placeholder="Contact"
              value="<?= htmlspecialchars($linkedName !== '' ? $linkedName : (string)($suggestion['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            />
            <button class="px-btn" style="padding:2px 8px;font-size:.85em" type="submit">Save</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
        Theme::footer();
    }

    // ── Link a call ─────────────────────────────────────────────────────────

    public static function handleLink(PDO $db, array $user, array $post): void
    {
        Auth::requireLogin();

        $callId      = (int)($post['call_id'] ?? 0);
        $caseRef     = trim((string)($post['case_ref'] ?? ''));
        $contactName = trim((string)($post['contact_name'] ?? ''));
        $e164        = trim((string)($post['phone_e164'] ?? ''));
        $rawPhone    = trim((string)($post['raw_phone'] ?? ''));
        $month       = trim((string)($post['month'] ?? ''));
        $extFilter   = trim((string)($post['ext'] ?? ''));

        if ($callId <= 0 || $caseRef === '') {
            http_response_code(400);
            echo "Bad request\n";
            return;
        }

        // Verify permission: check the call belongs to an allowed extension.
        $stmt = $db->prepare("SELECT ext FROM yealink_calls WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $callId]);
        $callExt = (string)($stmt->fetchColumn() ?: '');

        $allowedExts = Auth::allowedExtensions($db, $user);
        if (!self::canLink($user, $callExt, $allowedExts)) {
            http_response_code(403);
            echo "Forbidden\n";
            return;
        }

        // Insert call_links record.
        $db->prepare("
            INSERT INTO call_links
                (call_id, case_ref, phone_e164, contact_name, link_type, linked_by_user_id, linked_at)
            VALUES
                (:call_id, :case_ref, :phone_e164, :contact_name, 'manual', :uid, NOW(3))
        ")->execute([
            ':call_id'      => $callId,
            ':case_ref'     => $caseRef,
            ':phone_e164'   => $e164 !== '' ? $e164 : null,
            ':contact_name' => $contactName !== '' ? $contactName : null,
            ':uid'          => (int)($user['id'] ?? 0) ?: null,
        ]);

        // Update / create phonebook entry.
        if ($e164 !== '') {
            Phonebook::touch($db, $e164, $rawPhone, $caseRef, $contactName, (int)($user['id'] ?? 0) ?: null);
        }

        // Redirect back.
        $tz = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('now', $tz);
        $qs = '?month=' . urlencode($month !== '' ? $month : $now->format('Y-m'));
        if ($extFilter !== '') {
            $qs .= '&ext=' . urlencode($extFilter);
        }
        header('Location: /month' . $qs);
    }

    // ── CSV Export ──────────────────────────────────────────────────────────

    public static function exportCsv(PDO $db, array $user, array $get): void
    {
        Auth::requireLogin();

        $tz  = new DateTimeZone(self::TZ);
        $now = new DateTimeImmutable('now', $tz);

        $monthParam = trim((string)($get['month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            $monthParam = $now->format('Y-m');
        }

        [$year, $mon] = explode('-', $monthParam);
        $year = (int)$year;
        $mon  = (int)$mon;

        $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $mon), $tz);
        $end   = $start->modify('+1 month');

        $allowedExts = Auth::allowedExtensions($db, $user);
        $extFilter   = trim((string)($get['ext'] ?? ''));
        if ($extFilter !== '' && $allowedExts !== null && !in_array($extFilter, $allowedExts, true)) {
            $extFilter = '';
        }

        $calls = self::fetchCalls($db, $start, $end, $allowedExts, $extFilter);

        $filename = 'calls_' . $monthParam . ($extFilter !== '' ? '_' . $extFilter : '') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        if ($out === false) return;

        // UTF-8 BOM for Excel compatibility.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'Date/Time', 'Extension', 'Direction', 'Missed',
            'Remote URI', 'Phone E164', 'Duration (s)',
            'Case ref', 'Contact name', 'Linked at', 'Linked by',
        ]);

        foreach ($calls as $c) {
            $e164 = Phone::toE164((string)($c['remote_uri'] ?? '')) ?? '';
            $dur  = self::calcDurationSecs($c);
            fputcsv($out, [
                (string)($c['first_seen_at'] ?? ''),
                (string)($c['ext'] ?? ''),
                (string)($c['direction'] ?? ''),
                (int)($c['missed'] ?? 0) ? 'yes' : 'no',
                (string)($c['remote_uri'] ?? ''),
                $e164,
                $dur !== null ? (string)$dur : '',
                (string)($c['linked_case_ref'] ?? ''),
                (string)($c['linked_contact_name'] ?? ''),
                (string)($c['linked_at'] ?? ''),
                (string)($c['linked_by'] ?? ''),
            ]);
        }

        fclose($out);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Check whether the current user may link calls for a given extension.
     * - admin: always
     * - team_lead: only their allowed extensions
     * - user: only their own extension
     *
     * $allowedExts: null = all (admin), array = restricted list (team_lead/user)
     */
    private static function canLink(array $user, string $callExt, ?array $allowedExts): bool
    {
        $role = (string)($user['role'] ?? '');
        if ($role === 'admin') {
            return true;
        }
        if ($allowedExts === null) {
            return true;
        }
        return in_array($callExt, $allowedExts, true);
    }

    private static function fetchCalls(
        PDO $db,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?array $allowedExts,
        string $extFilter
    ): array {
        $utc = new DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $endUtc   = $end->setTimezone($utc)->format('Y-m-d H:i:s');

        $extCondition = '';
        $params = [':start' => $startUtc, ':end' => $endUtc];

        if ($extFilter !== '') {
            $extCondition = ' AND c.ext = :ext';
            $params[':ext'] = $extFilter;
        } elseif ($allowedExts !== null) {
            if ($allowedExts === []) {
                return [];
            }
            $placeholders = [];
            foreach ($allowedExts as $i => $e) {
                $key = ':ext' . $i;
                $placeholders[] = $key;
                $params[$key] = $e;
            }
            $extCondition = ' AND c.ext IN (' . implode(',', $placeholders) . ')';
        }

        $sql = "
            SELECT
                c.id,
                c.ext,
                c.first_seen_at,
                c.remote_uri,
                c.direction,
                c.missed,
                c.answered_at,
                c.ended_at,
                cl.case_ref        AS linked_case_ref,
                cl.contact_name    AS linked_contact_name,
                cl.linked_at,
                u.username         AS linked_by
            FROM yealink_calls c
            LEFT JOIN call_links cl
                ON cl.call_id = c.id
                AND cl.linked_at = (
                    SELECT MAX(cl2.linked_at) FROM call_links cl2 WHERE cl2.call_id = c.id
                )
            LEFT JOIN users u ON u.id = cl.linked_by_user_id
            WHERE c.first_seen_at >= :start
              AND c.first_seen_at <  :end
              $extCondition
            ORDER BY c.first_seen_at DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Build a map of e164 → suggestion for a list of remote URIs.
     * Returns [e164 => ['case_ref' => ..., 'contact_name' => ...]]
     */
    private static function buildSuggestions(PDO $db, array $remoteUris): array
    {
        $suggestions = [];
        $e164Map = [];

        foreach ($remoteUris as $uri) {
            $e164 = Phone::toE164($uri);
            if ($e164 !== null) {
                $e164Map[$e164] = true;
            }
        }

        if ($e164Map === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_keys($e164Map) as $i => $e164) {
            $key = ':e' . $i;
            $placeholders[] = $key;
            $params[$key] = $e164;
        }

        $sql = "
            SELECT phone_e164, case_ref, contact_name
              FROM phonebook_entries
             WHERE phone_e164 IN (" . implode(',', $placeholders) . ")
             ORDER BY last_used_at DESC, id DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $k = (string)$row['phone_e164'];
            if (!isset($suggestions[$k])) {
                $suggestions[$k] = $row; // first row = best (most recently used)
            }
        }

        return $suggestions;
    }

    private static function fetchExtList(PDO $db, DateTimeImmutable $start, DateTimeImmutable $end, ?array $allowedExts): array
    {
        $utc = new DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $endUtc   = $end->setTimezone($utc)->format('Y-m-d H:i:s');

        $params = [':start' => $startUtc, ':end' => $endUtc];
        $extCondition = '';

        if ($allowedExts !== null) {
            if ($allowedExts === []) return [];
            $placeholders = [];
            foreach ($allowedExts as $i => $e) {
                $key = ':ext' . $i;
                $placeholders[] = $key;
                $params[$key] = $e;
            }
            $extCondition = ' AND ext IN (' . implode(',', $placeholders) . ')';
        }

        $stmt = $db->prepare("
            SELECT DISTINCT ext FROM yealink_calls
             WHERE first_seen_at >= :start AND first_seen_at < :end
             $extCondition
             ORDER BY ext ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private static function formatDuration(array $call): string
    {
        $secs = self::calcDurationSecs($call);
        if ($secs === null) {
            return (bool)($call['missed'] ?? false) ? 'missed' : '—';
        }
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }

    private static function calcDurationSecs(array $call): ?int
    {
        if (empty($call['answered_at']) || empty($call['ended_at'])) {
            return null;
        }
        try {
            $a = new DateTimeImmutable((string)$call['answered_at']);
            $e = new DateTimeImmutable((string)$call['ended_at']);
            $diff = $e->getTimestamp() - $a->getTimestamp();
            return $diff >= 0 ? $diff : null;
        } catch (\Exception) {
            return null;
        }
    }

    private static function monthLabel(int $year, int $mon): string
    {
        $months = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        return ($months[$mon] ?? 'Month') . ' ' . $year;
    }
}

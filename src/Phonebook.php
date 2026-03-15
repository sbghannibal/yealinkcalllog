<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Phonebook
{
    // ── List / Search ───────────────────────────────────────────────────────

    public static function render(PDO $db, array $user, array $get, array $post, string $method): void
    {
        Auth::requireTeamLeadOrAdmin();

        $error   = '';
        $success = '';

        if ($method === 'POST') {
            $action = trim((string)($post['action'] ?? ''));

            if ($action === 'add') {
                try {
                    self::handleAdd($db, $user, $post);
                    $success = 'Entry added.';
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            } elseif ($action === 'delete') {
                $id = (int)($post['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare("DELETE FROM phonebook_entries WHERE id = :id")->execute([':id' => $id]);
                    $success = 'Entry deleted.';
                }
            }
        }

        $search = trim((string)($get['q'] ?? ''));

        $entries = self::fetchEntries($db, $search);
        $uid = (int)($user['id'] ?? 0);

        Theme::header('Phonebook', $user, 'phonebook');
        ?>
<div class="px-card">
  <h1 class="px-title">Phonebook</h1>
  <p class="px-sub">Link phone numbers to a contact name and case reference. A number may appear multiple times for different cases.</p>

  <?php if ($error !== ''): ?>
    <div class="px-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($success !== ''): ?>
    <div class="px-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="get" action="/phonebook" class="px-row" style="margin-bottom:12px">
    <div class="px-field" style="flex:1">
      <label>Search (number or name or case)</label>
      <input class="px-input" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. +32… or John or DOS-123" />
    </div>
    <div class="px-actions"><button class="px-btn secondary" type="submit">Search</button></div>
  </form>

  <table class="px-table">
    <thead>
      <tr>
        <th>Phone (E.164)</th>
        <th>Raw</th>
        <th>Contact name</th>
        <th>Case ref</th>
        <th>Last used</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($entries === []): ?>
        <tr><td colspan="6" class="px-muted" style="text-align:center">No entries found.</td></tr>
      <?php endif; ?>
      <?php foreach ($entries as $e): ?>
      <tr>
        <td><?= htmlspecialchars((string)($e['phone_e164'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td class="px-muted"><?= htmlspecialchars((string)($e['raw_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)($e['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string)($e['case_ref'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <td class="px-muted"><?= htmlspecialchars((string)($e['last_used_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td>
          <form method="post" action="/phonebook" onsubmit="return confirm('Delete this entry?');">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>" />
            <button class="px-btn danger" type="submit" style="padding:2px 10px;font-size:0.85em">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div style="height:16px"></div>

<div class="px-card">
  <h2 style="margin:0 0 12px">Add entry</h2>
  <form method="post" action="/phonebook" class="px-row">
    <input type="hidden" name="action" value="add" />
    <div class="px-field" style="min-width:180px">
      <label>Phone number</label>
      <input class="px-input" type="text" name="raw_phone" required placeholder="+32 xxx xx xx xx" />
    </div>
    <div class="px-field" style="min-width:220px;flex:1">
      <label>Contact name</label>
      <input class="px-input" type="text" name="contact_name" required placeholder="Jan Janssen" />
    </div>
    <div class="px-field" style="min-width:160px">
      <label>Case ref (dossier)</label>
      <input class="px-input" type="text" name="case_ref" required placeholder="DOS-2024-001" />
    </div>
    <div class="px-actions" style="align-self:flex-end">
      <button class="px-btn" type="submit">Add</button>
    </div>
  </form>
</div>
<?php
        Theme::footer();
    }

    // ── Internal helpers ────────────────────────────────────────────────────

    private static function handleAdd(PDO $db, array $user, array $post): void
    {
        $rawPhone    = trim((string)($post['raw_phone'] ?? ''));
        $contactName = trim((string)($post['contact_name'] ?? ''));
        $caseRef     = trim((string)($post['case_ref'] ?? ''));

        if ($rawPhone === '') {
            throw new \RuntimeException('Phone number is required.');
        }
        if ($contactName === '') {
            throw new \RuntimeException('Contact name is required.');
        }
        if ($caseRef === '') {
            throw new \RuntimeException('Case reference is required.');
        }

        $e164 = Phone::toE164($rawPhone);
        if ($e164 === null) {
            throw new \RuntimeException('Could not normalize phone number. Please use format: +32 xxx xx xx xx or 04xx xx xx xx.');
        }

        $stmt = $db->prepare("
            INSERT INTO phonebook_entries
                (phone_e164, raw_phone, contact_name, case_ref, created_by_user_id, created_at)
            VALUES
                (:e164, :raw, :name, :case_ref, :uid, NOW(3))
        ");
        $stmt->execute([
            ':e164'     => $e164,
            ':raw'      => $rawPhone,
            ':name'     => $contactName,
            ':case_ref' => $caseRef,
            ':uid'      => (int)($user['id'] ?? 0) ?: null,
        ]);
    }

    /**
     * Fetch phonebook entries, optionally filtered by a search term.
     * Always ordered by last_used_at DESC, id DESC (newest / most recently used first).
     */
    private static function fetchEntries(PDO $db, string $search): array
    {
        if ($search !== '') {
            $like       = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $searchE164 = Phone::toE164($search) ?? '';
            $stmt  = $db->prepare("
                SELECT id, phone_e164, raw_phone, contact_name, case_ref, last_used_at
                  FROM phonebook_entries
                 WHERE phone_e164 LIKE :like
                    OR raw_phone LIKE :like2
                    OR contact_name LIKE :like3
                    OR case_ref LIKE :like4
                    OR (:e164 <> '' AND phone_e164 = :e164)
                 ORDER BY last_used_at DESC, id DESC
                 LIMIT 200
            ");
            $stmt->execute([
                ':like'  => $like,
                ':like2' => $like,
                ':like3' => $like,
                ':like4' => $like,
                ':e164'  => $searchE164,
            ]);
        } else {
            $stmt = $db->query("
                SELECT id, phone_e164, raw_phone, contact_name, case_ref, last_used_at
                  FROM phonebook_entries
                 ORDER BY last_used_at DESC, id DESC
                 LIMIT 500
            ");
        }

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /**
     * Return the best suggestion (latest last_used_at) for a given E.164 number.
     * Used by Monthly page to pre-fill case/contact fields.
     */
    public static function suggest(PDO $db, string $e164): ?array
    {
        if ($e164 === '') {
            return null;
        }
        $stmt = $db->prepare("
            SELECT case_ref, contact_name
              FROM phonebook_entries
             WHERE phone_e164 = :e164
             ORDER BY last_used_at DESC, id DESC
             LIMIT 1
        ");
        $stmt->execute([':e164' => $e164]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Upsert a phonebook entry when a call is linked (updates last_used_at).
     * If a row already exists for (phone_e164, case_ref) update it; otherwise insert.
     */
    public static function touch(PDO $db, string $e164, string $rawPhone, string $caseRef, string $contactName, ?int $userId): void
    {
        if ($e164 === '' || $caseRef === '') {
            return;
        }

        // Check for existing entry
        $stmt = $db->prepare("
            SELECT id FROM phonebook_entries
             WHERE phone_e164 = :e164 AND case_ref = :case_ref
             LIMIT 1
        ");
        $stmt->execute([':e164' => $e164, ':case_ref' => $caseRef]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $db->prepare("
                UPDATE phonebook_entries
                   SET last_used_at = NOW(3),
                       contact_name = :name
                 WHERE id = :id
            ")->execute([':name' => $contactName, ':id' => (int)$existing]);
        } else {
            $db->prepare("
                INSERT INTO phonebook_entries
                    (phone_e164, raw_phone, contact_name, case_ref, created_by_user_id, created_at, last_used_at)
                VALUES
                    (:e164, :raw, :name, :case_ref, :uid, NOW(3), NOW(3))
            ")->execute([
                ':e164'     => $e164,
                ':raw'      => $rawPhone ?: $e164,
                ':name'     => $contactName,
                ':case_ref' => $caseRef,
                ':uid'      => $userId,
            ]);
        }
    }
}

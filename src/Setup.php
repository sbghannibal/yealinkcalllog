<?php
declare(strict_types=1);

namespace YealinkCallLog;

final class Setup
{
    /**
     * Admin-only page. Router enforces Auth::requireAdmin().
     */
    public static function render(Config $cfg, array $server, array $q): void
    {
        $baseUrl = self::baseUrl($server);
        $ingest  = $baseUrl . '/yealink/event';

        // Token support (best-effort): Config seems to expose $yealinkToken in your Ingest.php
        $token = null;
        if (property_exists($cfg, 'yealinkToken')) {
            /** @var mixed $t */
            $t = $cfg->yealinkToken;
            if (is_string($t) && $t !== '') {
                $token = $t;
            }
        } elseif (method_exists($cfg, 'yealinkToken')) {
            /** @var mixed $t */
            $t = $cfg->yealinkToken();
            if (is_string($t) && $t !== '') {
                $token = $t;
            }
        }

        // we want the token appended as &token=... (because these URLs already have ?event=...)
        $suffix = $token !== null ? '&token=' . $token : '';

        // NEW: include model parameter for phone type detection
        $modelParam = '&model=$model';

        $lines = [
            'action_url.call_established' =>
                $ingest . '?event=answered&ext=$active_user&call_id=$call_id&local=$local&remote=$remote' . $modelParam . $suffix,
            'action_url.call_terminated'  =>
                $ingest . '?event=ended&ext=$active_user&call_id=$call_id' . $modelParam . $suffix,
            'action_url.incoming_call'    =>
                $ingest . '?event=ringing&ext=$active_user&call_id=$call_id&local=$local&remote=$remote&dl=$display_local&dr=$display_remote' . $modelParam . $suffix,
            'action_url.missed_call'      =>
                $ingest . '?event=missed&ext=$active_user&call_id=$call_id&local=$local&remote=$remote' . $modelParam . $suffix,
            'action_url.outgoing_call'    =>
                $ingest . '?event=outgoing&ext=$active_user&call_id=$call_id&local=$local_uri&remote=$remote_uri&dl=$display_local&dr=$display_remote' . $modelParam . $suffix,
        ];

        Theme::header('Setup', Auth::currentUser(), 'setup');
        ?>
<div class="px-card">
  <h1 class="px-title">Setup</h1>
  <p class="px-sub">
    Copy/paste the following lines into your Yealink provisioning config.
  </p>

  <h2 style="margin:16px 0 10px">Yealink Action URLs</h2>

  <div class="px-field" style="margin-bottom:10px">
    <label>Base ingest endpoint</label>
    <input class="px-input" type="text" readonly value="<?= htmlspecialchars($ingest, ENT_QUOTES, 'UTF-8') ?>" />
  </div>

  <div class="px-field">
    <label>Provisioning lines (includes <code>model=$model</code>)</label>
    <textarea class="px-input" rows="9" readonly style="font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12.5px"><?= htmlspecialchars(self::formatProvisioningLines($lines), ENT_QUOTES, 'UTF-8') ?></textarea>
    <div class="px-muted">These URLs tell the server the phone model so it can be shown on the dashboard.</div>
  </div>

  <h2 style="margin:18px 0 10px">Quick links</h2>
  <div class="px-actions">
    <a class="px-btn" href="/dashboard">Dashboard</a>
    <a class="px-btn secondary" href="/calls">Calls</a>
    <a class="px-btn secondary" href="/admin/users">User management</a>
  </div>
</div>
<?php
        Theme::footer();
    }

    private static function formatProvisioningLines(array $lines): string
    {
        $out = [];
        foreach ($lines as $k => $v) {
            $out[] = $k . ' = ' . $v;
        }
        return implode("\n", $out);
    }

    private static function baseUrl(array $server): string
    {
        $https = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off');
        $proto = $https ? 'https' : 'http';
        $host = (string)($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        return $proto . '://' . $host;
    }
}
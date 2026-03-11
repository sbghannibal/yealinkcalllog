<?php
declare(strict_types=1);

namespace YealinkCallLog;

/**
 * /setup  –  Phone configuration helper
 *
 * Generates the four Yealink Action URLs (ringing / answered / ended / missed)
 * ready to copy-paste into the phone's web-interface or auto-provisioning file.
 */
final class Setup
{
    public static function render(Config $cfg, array $server, array $q): void
    {
        // Detect base URL from the incoming request, allow manual override via ?base=
        $defaultBase = self::detectBase($server);
        $base = rtrim(trim((string) ($q['base'] ?? $defaultBase)), '/');
        $tokenValue = (string) ($q['token_override'] ?? ($cfg->yealinkToken ?? ''));

        $actions = [
            'ringing'  => [
                'label'    => 'Incoming call (ringing)',
                'setting'  => 'Action URL – Incoming call',
                'yealink'  => 'features.action_url.incoming_call',
                'event'    => 'ringing',
            ],
            'outgoing' => [
                'label'    => 'Outgoing call',
                'setting'  => 'Action URL – Outgoing call',
                'yealink'  => 'features.action_url.outgoing_call',
                'event'    => 'outgoing',
            ],
            'answered' => [
                'label'    => 'Call answered',
                'setting'  => 'Action URL – Answer call',
                'yealink'  => 'features.action_url.answer_call',
                'event'    => 'answered',
            ],
            'ended'    => [
                'label'    => 'Call ended',
                'setting'  => 'Action URL – End call',
                'yealink'  => 'features.action_url.end_call',
                'event'    => 'ended',
            ],
            'missed'   => [
                'label'    => 'Missed call',
                'setting'  => 'Action URL – Missed call',
                'yealink'  => 'features.action_url.missed_call',
                'event'    => 'missed',
            ],
        ];

        header('Content-Type: text/html; charset=utf-8');
        ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Yealink Phone Setup &mdash; Action URLs</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body   { font-family: system-ui, Arial, sans-serif; margin: 0; padding: 24px; background: #f0f2f5; color: #222; }
    .card  { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.12); padding: 24px; max-width: 860px; margin: 0 auto 24px; }
    h1     { margin: 0 0 4px; font-size: 1.4rem; }
    h2     { font-size: 1rem; margin: 0 0 16px; color: #444; font-weight: 600; }
    .subtitle { color: #666; margin: 0 0 20px; font-size: .9rem; }
    label  { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; color: #333; }
    input[type=text], input[type=url] {
      width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
      font-size: .9rem; font-family: inherit;
    }
    input[type=text]:focus, input[type=url]:focus { outline: 2px solid #4a90d9; border-color: #4a90d9; }
    .field { margin-bottom: 16px; }
    .hint  { font-size: .78rem; color: #777; margin-top: 3px; }
    button[type=submit] {
      background: #4a90d9; color: #fff; border: none; border-radius: 4px;
      padding: 8px 20px; font-size: .9rem; cursor: pointer;
    }
    button[type=submit]:hover { background: #357abd; }

    /* URL rows */
    .url-list { display: flex; flex-direction: column; gap: 20px; }
    .url-item  { border: 1px solid #e0e0e0; border-radius: 6px; overflow: hidden; }
    .url-header {
      background: #f7f8fa; border-bottom: 1px solid #e0e0e0;
      padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;
    }
    .url-header strong { font-size: .95rem; }
    .url-header .setting-key { font-size: .78rem; color: #888; font-family: monospace; }
    .url-body  { padding: 12px 14px; }
    .url-row   { display: flex; gap: 8px; align-items: stretch; }
    .url-input {
      flex: 1; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
      font-family: monospace; font-size: .82rem; background: #fafafa; color: #333;
      word-break: break-all;
    }
    .copy-btn {
      flex-shrink: 0; background: #4a90d9; color: #fff; border: none; border-radius: 4px;
      padding: 0 16px; font-size: .85rem; cursor: pointer; white-space: nowrap;
    }
    .copy-btn:hover   { background: #357abd; }
    .copy-btn.copied  { background: #27ae60; }
    .url-meta { margin-top: 6px; font-size: .78rem; color: #888; }

    .token-warn {
      background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px;
      padding: 10px 14px; font-size: .85rem; color: #7a5800; margin-bottom: 20px;
    }
    .token-ok {
      background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 6px;
      padding: 10px 14px; font-size: .85rem; color: #1b5e20; margin-bottom: 20px;
    }
    .divider { border: none; border-top: 1px solid #eee; margin: 20px 0; }
    .nav { font-size: .85rem; margin-top: 16px; }
    .nav a { color: #4a90d9; text-decoration: none; margin-right: 16px; }
    .nav a:hover { text-decoration: underline; }
  </style>
</head>
<body>

<div class="card">
  <h1>&#128222; Yealink Phone Setup</h1>
  <p class="subtitle">Generate the Action URLs to paste into your Yealink phone(s).</p>

  <form method="get" action="/setup">
    <div class="field">
      <label for="base">Server base URL</label>
      <input type="url" id="base" name="base"
             value="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="https://your-server.example.com" />
      <div class="hint">Auto-detected from this request. Override if your phones reach the server via a different hostname or IP.</div>
    </div>

    <?php if ($cfg->yealinkToken === null): ?>
    <div class="field">
      <label for="token_override">Token (optional)</label>
      <input type="text" id="token_override" name="token_override"
             value="<?= htmlspecialchars($tokenValue, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="leave blank if not using token protection" />
      <div class="hint">Set <code>YEALINK_TOKEN</code> on the server to require this token on every phone request.</div>
    </div>
    <?php endif; ?>

    <button type="submit">&#8635; Regenerate URLs</button>
  </form>
</div>

<div class="card">
  <h2>Action URLs</h2>

  <?php if ($cfg->yealinkToken !== null): ?>
  <div class="token-ok">&#128274; Token protection is <strong>enabled</strong> on this server. The token is included in the URLs below.</div>
  <?php elseif ($tokenValue !== ''): ?>
  <div class="token-warn">&#9888; You entered a token above, but <code>YEALINK_TOKEN</code> is <strong>not set</strong> on the server, so it will be ignored. Set the env var to enable protection.</div>
  <?php else: ?>
  <div class="token-warn">&#9888; Token protection is <strong>disabled</strong>. Anyone who knows the URL can log calls. Set <code>YEALINK_TOKEN</code> on the server to require a shared secret.</div>
  <?php endif; ?>

  <p style="font-size:.85rem;color:#555;margin-bottom:16px;">
    Paste each URL into the matching field in your phone's web interface at<br>
    <strong>Features &rarr; Action URL</strong> (or upload via auto-provisioning).
  </p>

  <div class="url-list">
    <?php foreach ($actions as $key => $info):
        $url = self::buildUrl($base, $info['event'], $tokenValue);
    ?>
    <div class="url-item">
      <div class="url-header">
        <strong><?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?></strong>
        <span class="setting-key"><?= htmlspecialchars($info['yealink'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="url-body">
        <div class="url-row">
          <input class="url-input" id="url-<?= $key ?>" type="text" readonly
                 value="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                 onclick="this.select()" />
          <button class="copy-btn" onclick="copyUrl('url-<?= $key ?>', this)">Copy</button>
        </div>
        <div class="url-meta">
          Web UI setting: <strong><?= htmlspecialchars($info['setting'], ENT_QUOTES, 'UTF-8') ?></strong>
          &nbsp;&middot;&nbsp; Auto-provisioning key: <code><?= htmlspecialchars($info['yealink'], ENT_QUOTES, 'UTF-8') ?></code>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="font-size:.85rem;color:#555;">
  <strong>Yealink variables used in the URLs:</strong>
  <ul style="margin:8px 0 0;padding-left:20px;line-height:1.8;">
    <li><code>$active_user</code> &mdash; SIP account user / extension number</li>
    <li><code>$call_id</code> &mdash; Unique call identifier</li>
    <li><code>$local_uri</code> / <code>$display_local</code> &mdash; Local SIP URI and display name</li>
    <li><code>$remote_uri</code> / <code>$display_remote</code> &mdash; Remote SIP URI and display name</li>
  </ul>
  <hr class="divider" />
  <strong>How to configure (web interface):</strong><br>
  Log in to the phone &rarr; <em>Features</em> &rarr; <em>Action URL</em> &rarr; paste the matching URL &rarr; <em>Confirm</em>.<br><br>
  <strong>How to configure (auto-provisioning):</strong><br>
  Add the key-value lines to your <code>y0000000000xx.cfg</code> / per-phone cfg file and re-provision.
  <div class="nav">
    <a href="/dashboard">&#128202; Dashboard</a>
  </div>
</div>

<script>
function copyUrl(inputId, btn) {
  var input = document.getElementById(inputId);
  if (!input) return;
  input.select();
  input.setSelectionRange(0, 99999);
  try {
    navigator.clipboard.writeText(input.value).then(function () {
      btn.textContent = 'Copied!';
      btn.classList.add('copied');
      setTimeout(function () {
        btn.textContent = 'Copy';
        btn.classList.remove('copied');
      }, 2000);
    });
  } catch (e) {
    // Fallback for older browsers
    document.execCommand('copy');
    btn.textContent = 'Copied!';
    btn.classList.add('copied');
    setTimeout(function () {
      btn.textContent = 'Copy';
      btn.classList.remove('copied');
    }, 2000);
  }
}
</script>

</body>
</html>
        <?php
    }

    // -------------------------------------------------------------------------

    private static function detectBase(array $server): string
    {
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    private static function buildUrl(string $base, string $event, string $token): string
    {
        $params = [
            'event'          => $event,
            'ext'            => '$active_user',
            'call_id'        => '$call_id',
            'local'          => '$local_uri',
            'remote'         => '$remote_uri',
            'dl'             => '$display_local',
            'dr'             => '$display_remote',
        ];

        if ($token !== '') {
            $params['token'] = $token;
        }

        // Build query string manually so Yealink variables ($active_user etc.)
        // are NOT percent-encoded — phones expect them as literal dollar-sign strings.
        $parts = [];
        foreach ($params as $k => $v) {
            if (str_starts_with($v, '$')) {
                // Yealink variable — keep as-is (no urlencode on the value).
                $parts[] = urlencode($k) . '=' . $v;
            } else {
                $parts[] = urlencode($k) . '=' . urlencode($v);
            }
        }

        return $base . '/yealink/event?' . implode('&', $parts);
    }
}

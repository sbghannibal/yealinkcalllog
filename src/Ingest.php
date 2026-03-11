<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Ingest
{
    private const ALLOWED_EVENTS = ['ringing', 'answered', 'ended', 'missed', 'outgoing'];

    public static function handle(PDO $db, Config $cfg, array $q, array $server): void
    {
        // Optional shared-secret protection (constant-time comparison).
        if ($cfg->yealinkToken !== null) {
            $provided = isset($q['token']) ? (string) $q['token'] : '';
            if (!hash_equals($cfg->yealinkToken, $provided)) {
                http_response_code(401);
                header('Content-Type: text/plain; charset=utf-8');
                echo "Unauthorized\n";
                return;
            }
        }

        $event = strtolower(trim((string) ($q['event'] ?? '')));
        if (!in_array($event, self::ALLOWED_EVENTS, true)) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Invalid or missing event. Must be one of: " . implode(', ', self::ALLOWED_EVENTS) . "\n";
            return;
        }

        $ext    = trim((string) ($q['ext']     ?? ''));
        $callId = trim((string) ($q['call_id'] ?? ''));

        if ($ext === '' || $callId === '') {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Missing required parameter: ext and call_id are required\n";
            return;
        }

        $local  = isset($q['local'])          ? (string) $q['local']          : null;
        $remote = isset($q['remote'])         ? (string) $q['remote']         : null;
        $dl     = isset($q['dl'])             ? (string) $q['dl']
                : (isset($q['display_local'])  ? (string) $q['display_local']  : null);
        $dr     = isset($q['dr'])             ? (string) $q['dr']
                : (isset($q['display_remote']) ? (string) $q['display_remote'] : null);

        $ip = $server['REMOTE_ADDR'] ?? null;

        // 1) Insert raw event row.
        $stmt = $db->prepare("
            INSERT INTO yealink_events
                (received_at, ext, call_id, event, local_uri, remote_uri, display_local, display_remote, source_ip)
            VALUES
                (NOW(3), :ext, :call_id, :event, :local_uri, :remote_uri, :display_local, :display_remote, :source_ip)
        ");
        $stmt->execute([
            ':ext'            => $ext,
            ':call_id'        => $callId,
            ':event'          => $event,
            ':local_uri'      => $local,
            ':remote_uri'     => $remote,
            ':display_local'  => $dl,
            ':display_remote' => $dr,
            ':source_ip'      => $ip,
        ]);

        // 2) Upsert per-call summary row.
        $timeCol  = match ($event) {
            'ringing'  => 'ringing_at',
            'answered' => 'answered_at',
            'ended'    => 'ended_at',
            'missed'   => 'missed_at',
            'outgoing' => 'outgoing_at',
        };
        $received  = ($event === 'ringing')   ? 1 : 0;
        $answered  = ($event === 'answered')  ? 1 : 0;
        $missed    = ($event === 'missed')    ? 1 : 0;
        $direction = match ($event) {
            'ringing'  => 'in',
            'outgoing' => 'out',
            default    => null,
        };

        // phpcs:disable
        $sql = "
            INSERT INTO yealink_calls
                (ext, call_id, first_seen_at, {$timeCol},
                 local_uri, remote_uri, display_local, display_remote,
                 received, answered, missed, direction)
            VALUES
                (:ext, :call_id, NOW(3), NOW(3),
                 :local_uri, :remote_uri, :display_local, :display_remote,
                 :received, :answered, :missed, :direction)
            ON DUPLICATE KEY UPDATE
                {$timeCol}     = COALESCE({$timeCol}, NOW(3)),
                local_uri      = COALESCE(local_uri,      VALUES(local_uri)),
                remote_uri     = COALESCE(remote_uri,     VALUES(remote_uri)),
                display_local  = COALESCE(display_local,  VALUES(display_local)),
                display_remote = COALESCE(display_remote, VALUES(display_remote)),
                received       = GREATEST(received,  VALUES(received)),
                answered       = GREATEST(answered,  VALUES(answered)),
                missed         = GREATEST(missed,    VALUES(missed)),
                direction      = COALESCE(direction, VALUES(direction))
        ";
        // phpcs:enable

        $db->prepare($sql)->execute([
            ':ext'            => $ext,
            ':call_id'        => $callId,
            ':local_uri'      => $local,
            ':remote_uri'     => $remote,
            ':display_local'  => $dl,
            ':display_remote' => $dr,
            ':received'       => $received,
            ':answered'       => $answered,
            ':missed'         => $missed,
            ':direction'      => $direction,
        ]);

        header('Content-Type: text/plain; charset=utf-8');
        echo "OK\n";
    }
}

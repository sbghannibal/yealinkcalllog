<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Migrations
{
    private const SCHEMA_VERSION = 3;

    public static function migrate(PDO $db): void
    {
        // Create tracking table first so we can check the current version.
        $db->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
                id          INT          NOT NULL PRIMARY KEY,
                migrated_at DATETIME(3)  NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $current = (int) $db->query(
            "SELECT COALESCE(MAX(id), 0) AS v FROM schema_migrations"
        )->fetchColumn();

        if ($current < 1) {
            self::v1($db);
        }
        if ($current < 2) {
            self::v2($db);
        }
        if ($current < 3) {
            self::v3($db);
        }
    }

    private static function v1(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS yealink_events (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                received_at    DATETIME(3)     NOT NULL,
                ext            VARCHAR(64)     NOT NULL,
                call_id        VARCHAR(128)    NOT NULL,
                event          ENUM('ringing','answered','ended','missed') NOT NULL,
                local_uri      VARCHAR(255)    NULL,
                remote_uri     VARCHAR(255)    NULL,
                display_local  VARCHAR(255)    NULL,
                display_remote VARCHAR(255)    NULL,
                source_ip      VARCHAR(45)     NULL,
                KEY idx_received_at     (received_at),
                KEY idx_ext_received_at (ext, received_at),
                KEY idx_call            (ext, call_id),
                KEY idx_event           (event)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS yealink_calls (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ext            VARCHAR(64)     NOT NULL,
                call_id        VARCHAR(128)    NOT NULL,
                first_seen_at  DATETIME(3)     NOT NULL,
                ringing_at     DATETIME(3)     NULL,
                answered_at    DATETIME(3)     NULL,
                ended_at       DATETIME(3)     NULL,
                missed_at      DATETIME(3)     NULL,
                local_uri      VARCHAR(255)    NULL,
                remote_uri     VARCHAR(255)    NULL,
                display_local  VARCHAR(255)    NULL,
                display_remote VARCHAR(255)    NULL,
                received       TINYINT(1)      NOT NULL DEFAULT 0,
                answered       TINYINT(1)      NOT NULL DEFAULT 0,
                missed         TINYINT(1)      NOT NULL DEFAULT 0,
                UNIQUE KEY uniq_call          (ext, call_id),
                KEY idx_first_seen_at         (first_seen_at),
                KEY idx_ext_first_seen_at     (ext, first_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->prepare(
            "INSERT INTO schema_migrations (id, migrated_at) VALUES (1, NOW(3))"
        )->execute();
    }

    private static function v2(PDO $db): void
    {
        // Extend yealink_events to accept the 'outgoing' event type.
        $db->exec("
            ALTER TABLE yealink_events
                MODIFY COLUMN event ENUM('ringing','answered','ended','missed','outgoing') NOT NULL
        ");

        // Add direction and outgoing_at columns to yealink_calls.
        $db->exec("
            ALTER TABLE yealink_calls
                ADD COLUMN direction    VARCHAR(3)  NULL AFTER missed_at,
                ADD COLUMN outgoing_at  DATETIME(3) NULL AFTER direction
        ");

        // Backfill direction for existing calls:
        // calls with ringing_at set were incoming calls.
        $db->exec("
            UPDATE yealink_calls
               SET direction = 'in'
             WHERE direction IS NULL
               AND ringing_at IS NOT NULL
        ");

        $db->prepare(
            "INSERT INTO schema_migrations (id, migrated_at) VALUES (2, NOW(3))"
        )->execute();
    }

    private static function v3(PDO $db): void
    {
        // Users table for session-based authentication.
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username   VARCHAR(64)  NOT NULL,
                password   VARCHAR(255) NOT NULL,
                role       ENUM('admin','user') NOT NULL DEFAULT 'user',
                ext        VARCHAR(64)  NULL,
                created_at DATETIME(3)  NOT NULL,
                UNIQUE KEY uniq_username (username),
                KEY idx_role (role)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->prepare(
            "INSERT INTO schema_migrations (id, migrated_at) VALUES (3, NOW(3))"
        )->execute();
    }
}

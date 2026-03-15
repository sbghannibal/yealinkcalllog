<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Migrations
{
    private const SCHEMA_VERSION = 5;

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
        if ($current < 4) {
            self::v4($db);
        }
        if ($current < 5) {
            self::v5($db);
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

    private static function v4(PDO $db): void
    {
        // Add team_lead role to the users enum.
        // MODIFY COLUMN is safe here: 'admin' and 'user' are kept; 'team_lead' is added.
        $db->exec("
            ALTER TABLE users
                MODIFY COLUMN role ENUM('admin','user','team_lead') NOT NULL DEFAULT 'user'
        ");

        // Table for team lead → extension assignments.
        $db->exec("
            CREATE TABLE IF NOT EXISTS team_lead_exts (
                team_lead_user_id INT UNSIGNED NOT NULL,
                ext               VARCHAR(64)  NOT NULL,
                PRIMARY KEY (team_lead_user_id, ext),
                KEY idx_tle_user (team_lead_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Customers (companies / individuals that own cases).
        $db->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name       VARCHAR(255)    NOT NULL,
                created_at DATETIME(3)     NOT NULL,
                KEY idx_customers_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Cases / dossiers.
        $db->exec("
            CREATE TABLE IF NOT EXISTS `cases` (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                case_ref    VARCHAR(64)     NOT NULL,
                title       VARCHAR(255)    NULL,
                customer_id BIGINT UNSIGNED NULL,
                created_at  DATETIME(3)     NOT NULL,
                UNIQUE KEY uniq_case_ref (case_ref),
                KEY idx_cases_customer (customer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Links between calls and cases (one call can have multiple historical links).
        $db->exec("
            CREATE TABLE IF NOT EXISTS call_links (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                call_id           BIGINT UNSIGNED NOT NULL,
                case_id           BIGINT UNSIGNED NULL,
                customer_id       BIGINT UNSIGNED NULL,
                link_type         VARCHAR(32)     NOT NULL DEFAULT 'manual',
                linked_by_user_id INT UNSIGNED    NULL,
                linked_at         DATETIME(3)     NOT NULL,
                KEY idx_cl_call       (call_id),
                KEY idx_cl_case       (case_id),
                KEY idx_cl_linked_at  (linked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->prepare(
            "INSERT INTO schema_migrations (id, migrated_at) VALUES (4, NOW(3))"
        )->execute();
    }

    private static function v5(PDO $db): void
    {
        // Phonebook: maps phone numbers to contacts and cases.
        // A number may appear multiple times (one row per case linkage).
        $db->exec("
            CREATE TABLE IF NOT EXISTS phonebook_entries (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                phone_e164          VARCHAR(32)     NOT NULL,
                raw_phone           VARCHAR(64)     NOT NULL DEFAULT '',
                contact_name        VARCHAR(255)    NOT NULL DEFAULT '',
                customer_id         BIGINT UNSIGNED NULL,
                case_id             BIGINT UNSIGNED NULL,
                case_ref            VARCHAR(64)     NOT NULL DEFAULT '',
                created_by_user_id  INT UNSIGNED    NULL,
                created_at          DATETIME(3)     NOT NULL,
                last_used_at        DATETIME(3)     NULL,
                KEY idx_pb_phone      (phone_e164),
                KEY idx_pb_case       (case_id),
                KEY idx_pb_case_ref   (case_ref),
                KEY idx_pb_customer   (customer_id),
                KEY idx_pb_last_used  (phone_e164, last_used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add phone_e164, contact_name, case_ref to call_links if not present.
        $existingCols = [];
        $res = $db->query("SHOW COLUMNS FROM call_links");
        if ($res !== false) {
            foreach ($res->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $existingCols[] = strtolower((string)($col['Field'] ?? ''));
            }
        }

        if (!in_array('case_ref', $existingCols, true)) {
            $db->exec("ALTER TABLE call_links ADD COLUMN case_ref VARCHAR(64) NULL AFTER case_id");
        }
        if (!in_array('phone_e164', $existingCols, true)) {
            $db->exec("ALTER TABLE call_links ADD COLUMN phone_e164 VARCHAR(32) NULL AFTER customer_id");
        }
        if (!in_array('contact_name', $existingCols, true)) {
            $db->exec("ALTER TABLE call_links ADD COLUMN contact_name VARCHAR(255) NULL AFTER phone_e164");
        }

        // Add index on (phone_e164, linked_at) – skip if it already exists (MySQL error 1061).
        try {
            $db->exec("ALTER TABLE call_links ADD KEY idx_cl_phone_linked (phone_e164, linked_at)");
        } catch (\PDOException $e) {
            // 1061 = Duplicate key name; safe to ignore.
            if ((string)$e->getCode() !== '42000' && strpos($e->getMessage(), '1061') === false) {
                throw $e;
            }
        }

        $db->prepare(
            "INSERT INTO schema_migrations (id, migrated_at) VALUES (5, NOW(3))"
        )->execute();
    }
}

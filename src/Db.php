<?php
declare(strict_types=1);

namespace YealinkCallLog;

use PDO;

final class Db
{
    public static function pdo(Config $cfg): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg->dbHost,
            $cfg->dbPort,
            $cfg->dbName
        );

        return new PDO($dsn, $cfg->dbUser, $cfg->dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}

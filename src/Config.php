<?php
declare(strict_types=1);

namespace YealinkCallLog;

final class Config
{
    public function __construct(
        public readonly string $dbHost,
        public readonly int    $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly ?string $yealinkToken
    ) {}

    public static function fromEnv(): self
    {
        $host  = getenv('DB_HOST')        ?: '127.0.0.1';
        $port  = (int)(getenv('DB_PORT')  ?: 3306);
        $name  = getenv('DB_NAME')        ?: 'yealinkcalllog';
        $user  = getenv('DB_USER')        ?: 'root';
        $pass  = getenv('DB_PASS')        ?: '';
        $token = getenv('YEALINK_TOKEN')  ?: null;

        return new self($host, $port, $name, $user, $pass, $token ?: null);
    }
}

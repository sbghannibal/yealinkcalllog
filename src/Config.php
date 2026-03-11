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

    /**
     * Load a .env file from the given path into the process environment.
     * Lines starting with # and empty lines are ignored.
     * Values are NOT overwritten if the variable is already set (e.g. via
     * Apache SetEnv or a PHP-FPM pool configuration).
     */
    public static function loadDotEnv(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments.
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Split on the first '=' only.
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip optional surrounding quotes (" or ').
            if (strlen($value) >= 2
                && (($value[0] === '"'  && $value[-1] === '"')
                 || ($value[0] === '\'' && $value[-1] === '\''))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key === '') {
                continue;
            }

            // Do not overwrite variables already set in the environment.
            if (!array_key_exists($key, $_ENV) && getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

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

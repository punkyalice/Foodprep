<?php
declare(strict_types=1);

namespace App;

final class Config
{
    public static function env(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);
        if ($v === false || $v === null || $v === '') return $default;
        return (string)$v;
    }
}

<?php
declare(strict_types=1);

require __DIR__ . '/Config.php';
require __DIR__ . '/Db.php';

spl_autoload_register(function(string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
});

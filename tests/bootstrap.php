<?php

declare(strict_types=1);

// Composer's autoloader when it is there, a two-line PSR-4 loader when it is
// not, so the tests run from a checkout with nothing but PHP.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Aamio\\')) {
            require __DIR__ . '/../src/' . substr($class, 6) . '.php';
        }
    });
}

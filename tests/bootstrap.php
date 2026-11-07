<?php

declare(strict_types=1);

// Composer's autoloader when it is there, a two-line PSR-4 loader when it is
// not, so the tests run from a checkout with nothing but PHP.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        // Only what is there: an add-on's classes are under Aamio\ too, and
        // class_exists on one of them must answer false, not stop everything.
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (str_starts_with($class, 'Aamio\\') && is_file($file)) {
            require $file;
        }
    });
}

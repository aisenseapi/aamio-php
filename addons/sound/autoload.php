<?php

declare(strict_types=1);

// The add-on's own classes, for a process aamio starts with nothing but PHP, for
// the live test, and for a checkout Composer has not registered. Idempotent: a
// class Composer already knows is left to Composer.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Aamio\\Sound\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Aamio\\Sound\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

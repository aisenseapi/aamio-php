<?php

declare(strict_types=1);

// The add-on, for aamio's registry: this is the file the core requires, found
// through Composer when the package is installed, or named in AAMIO_ADDONS for a
// checkout that is not. Where Composer has not registered the classes, as in a
// checkout, the add-on loads its own.
if (!class_exists(\Aamio\DomainKeys\Addon::class)) {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Aamio\\DomainKeys\\')) {
            $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Aamio\\DomainKeys\\'))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

return new \Aamio\DomainKeys\Addon();

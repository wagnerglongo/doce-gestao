<?php

declare(strict_types=1);

/**
 * Carrega as classes sem precisar do Composer (hospedagem compartilhada).
 * Se existir vendor/autoload.php, ele é usado no lugar deste.
 */
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Confeitaria\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('Confeitaria\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/helpers.php';

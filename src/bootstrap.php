<?php

declare(strict_types=1);

/**
 * Ponto de partida comum (site e scripts):
 * 1. carrega as classes (Composer se houver, senão o autoload próprio);
 * 2. lê o config.php da raiz, se existir, e expõe os valores como variáveis de ambiente.
 */
$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : __DIR__ . '/autoload.php';

if (is_file($root . '/config.php')) {
    foreach ((array) require $root . '/config.php' as $name => $value) {
        if (getenv($name) === false && $value !== null && $value !== '') {
            putenv("{$name}={$value}");
        }
    }
}

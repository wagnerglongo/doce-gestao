<?php

declare(strict_types=1);

/**
 * Apaga os dados de uso (ingredientes, receitas, produção, compras, vendas e despesas)
 * para entregar o sistema limpo a um cliente. Mantém os usuários.
 *
 *   php bin/limpar.php --confirmar                 mantém a configuração da precificação
 *   php bin/limpar.php --confirmar --precificacao  zera também a precificação
 */

use Confeitaria\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Rode pelo terminal.\n");
}
if (!in_array('--confirmar', $argv, true)) {
    exit("Isso apaga ingredientes, receitas, produção, compras, vendas e despesas (os usuários ficam).\n"
        . "Para continuar: php bin/limpar.php --confirmar\n");
}

$pdo = Database::connect();
Database::upgrade($pdo);

// Ordem: primeiro o que depende de outras tabelas
$tables = ['sales', 'productions', 'purchases', 'recipe_items', 'recipes', 'ingredients', 'expenses', 'login_attempts'];
$pdo->beginTransaction();
foreach ($tables as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
if (in_array('--precificacao', $argv, true)) {
    $pdo->exec("DELETE FROM settings WHERE name <> 'schema_version'");
}
$pdo->commit();

// Recomeça a numeração dos IDs
foreach ($tables as $table) {
    Database::isSqlite($pdo)
        ? $pdo->exec("DELETE FROM sqlite_sequence WHERE name = '{$table}'")
        : $pdo->exec("ALTER TABLE {$table} AUTO_INCREMENT = 1");
}

echo "✔ Dados apagados. Usuários mantidos" . (in_array('--precificacao', $argv, true) ? ', precificação zerada' : ', precificação mantida') . ".\n";

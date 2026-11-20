<?php

declare(strict_types=1);

/**
 * Cria as tabelas, o usuário e (opcional) 30 dias de dados de exemplo.
 *   ADMIN_EMAIL=... ADMIN_PASSWORD=... php bin/setup.php                (com dados de exemplo)
 *   ADMIN_EMAIL=... ADMIN_PASSWORD=... php bin/setup.php --sem-exemplo  (cliente: começa vazio)
 * O banco vem do config.php (ou das variáveis DB_DSN, DB_USER e DB_PASSWORD).
 */

use Confeitaria\Database;
use Confeitaria\Domain\Inventory;
use Confeitaria\Domain\Pricing;
use Confeitaria\Domain\RecipeCost;
use Confeitaria\Service\Auth;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::connect();
Database::upgrade($pdo);

$empty = (int) $pdo->query('SELECT COUNT(*) FROM ingredients')->fetchColumn() === 0;
if ($empty && !in_array('--sem-exemplo', $argv, true)) {
    mt_srand(42); // dados de exemplo sempre iguais
    $inventory = new Inventory($pdo, new RecipeCost($pdo));
    $today = new DateTimeImmutable();
    $day = fn (int $ago) => $today->modify("-{$ago} days")->format('Y-m-d');

    $ingredient = function (string $name, string $unit, float $min, string $kind = 'ingrediente') use ($pdo): int {
        $pdo->prepare('INSERT INTO ingredients (name, unit, kind, min_stock) VALUES (?, ?, ?, ?)')->execute([$name, $unit, $kind, $min]);
        return (int) $pdo->lastInsertId();
    };
    // Ingredientes, embalagens e um item de revenda
    $leite = $ingredient('Leite condensado', 'g', 3000);
    $creme = $ingredient('Creme de leite', 'g', 1500);
    $cacau = $ingredient('Chocolate em pó 50%', 'g', 500);
    $manteiga = $ingredient('Manteiga sem sal', 'g', 500);
    $ninho = $ingredient('Leite em pó', 'g', 800);
    $nutella = $ingredient('Creme de avelã', 'g', 650);
    $granulado = $ingredient('Granulado ao leite', 'g', 400);
    $morango = $ingredient('Morango', 'un', 60);
    $wafer = $ingredient('Chocolate wafer', 'un', 20);
    $farinha = $ingredient('Farinha de trigo', 'g', 2000);
    $acucar = $ingredient('Açúcar refinado', 'g', 2000);
    $ovos = $ingredient('Ovos', 'un', 30);
    $leiteIntegral = $ingredient('Leite integral', 'ml', 2000);
    $forminha = $ingredient('Forminha nº 4', 'un', 300, 'embalagem');
    $copo = $ingredient('Copo 300 ml com tampa', 'un', 40, 'embalagem');
    $marmita = $ingredient('Embalagem bolo gelado', 'un', 30, 'embalagem');
    $refri = $ingredient('Refrigerante lata 350 ml', 'un', 24);

    // Compras iniciais (quantidade, total em centavos)
    foreach ([[$leite, 15000, 22500], [$creme, 6000, 8400], [$cacau, 3000, 14600], [$manteiga, 3000, 16800], [$ninho, 3800, 20060],
              [$nutella, 2600, 16800], [$granulado, 2000, 15000], [$morango, 150, 10000], [$wafer, 60, 18000], [$farinha, 10000, 5500],
              [$acucar, 10000, 5000], [$ovos, 120, 12000], [$leiteIntegral, 12000, 6000], [$forminha, 1000, 3500],
              [$copo, 200, 16000], [$marmita, 100, 12000], [$refri, 48, 17856]] as [$id, $q, $total]) {
        $inventory->purchase($id, $q, $total, $day(30), 'Atacado Exemplo');
    }

    // [nome, rendimento, unidade, preço delivery, vendido?, itens, minutos de trabalho, preço iFood]
    $recipe = function (string $name, float $yield, string $unit, int $price, bool $sellable, array $items, float $minutes = 0, int $appPrice = 0) use ($pdo): int {
        $pdo->prepare('INSERT INTO recipes (name, yield, yield_unit, price_cents, app_price_cents, labor_minutes, sellable) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$name, $yield, $unit, $price, $appPrice, $minutes, $sellable ? 1 : 0]);
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO recipe_items (recipe_id, ingredient_id, sub_recipe_id, quantity) VALUES (?, ?, ?, ?)');
        foreach ($items as [$type, $itemId, $q]) {
            $stmt->execute([$id, $type === 'i' ? $itemId : null, $type === 'r' ? $itemId : null, $q]);
        }
        return $id;
    };
    // Bases (usadas dentro dos produtos)
    $brigadeiroBase = $recipe('Massa de brigadeiro', 1000, 'g', 0, false, [['i', $leite, 790], ['i', $creme, 200], ['i', $cacau, 60], ['i', $manteiga, 30]]);
    $recheioNinho = $recipe('Recheio de leite em pó', 1000, 'g', 0, false, [['i', $leite, 790], ['i', $creme, 200], ['i', $ninho, 120]]);
    $massaBolo = $recipe('Massa de bolo de chocolate', 1200, 'g', 0, false, [['i', $farinha, 300], ['i', $acucar, 250], ['i', $ovos, 4], ['i', $cacau, 80], ['i', $leiteIntegral, 240], ['i', $manteiga, 100]]);
    // Produtos vendidos
    $brigadeiro = $recipe('Brigadeiro gourmet 30 g', 40, 'un', 400, true, [['r', $brigadeiroBase, 1040], ['i', $granulado, 240], ['i', $forminha, 40]], 60, 550);
    $docinhoNinho = $recipe('Docinho de leite em pó com avelã', 40, 'un', 450, true, [['r', $recheioNinho, 1000], ['i', $nutella, 160], ['i', $ninho, 80], ['i', $forminha, 40]], 70, 600);
    $copoMorango = $recipe('Copo da felicidade com morango', 10, 'un', 2000, true, [['r', $recheioNinho, 900], ['r', $massaBolo, 600], ['i', $morango, 40], ['i', $copo, 10]], 50, 2700);
    $copoWafer = $recipe('Copo de brigadeiro com wafer', 10, 'un', 2000, true, [['r', $brigadeiroBase, 1000], ['r', $massaBolo, 600], ['i', $wafer, 20], ['i', $copo, 10]], 50, 1800);
    $boloGelado = $recipe('Bolo gelado de brigadeiro', 12, 'un', 1600, true, [['r', $massaBolo, 1200], ['r', $brigadeiroBase, 1200], ['i', $marmita, 12]], 60, 2200);
    $refriVenda = $recipe('Refrigerante lata (revenda)', 1, 'un', 600, true, [['i', $refri, 1]], 0, 800);

    // Precificação: hora de trabalho, custos fixos, taxas por canal, impostos e lucro padrão
    Pricing::save($pdo, ['hourly_rate_cents' => 1600, 'monthly_fixed_cents' => 450000, 'monthly_units' => 2500,
        'fee_own_percent' => 3, 'fee_app_percent' => 27, 'tax_percent' => 6, 'profit_percent' => 15]);

    // 30 dias de operação: produz quando precisa, vende todo dia
    for ($ago = 29; $ago >= 0; $ago--) {
        $date = $day($ago);
        if ($ago % 5 === 0) {
            foreach ([[$leite, 10000, 15000], [$creme, 4000, 5600], [$cacau, 1000, 4870], [$manteiga, 1000, 5600], [$ninho, 1520, 8024],
                      [$nutella, 1300, 8400], [$granulado, 800, 6000], [$morango, 90, 6000], [$wafer, 40, 12000], [$farinha, 2000, 1100],
                      [$acucar, 2000, 1000], [$ovos, 60, 6000], [$leiteIntegral, 4000, 2000], [$copo, 100, 8000], [$marmita, 50, 6000],
                      [$refri, 24, 8928]] as [$id, $q, $total]) {
                $inventory->purchase($id, $q, $total, $date, 'Atacado Exemplo');
            }
        }
        // [receita, fornadas, produz quando o estoque fica abaixo de]
        foreach ([[$brigadeiroBase, 4, 3000], [$recheioNinho, 3, 2500], [$massaBolo, 3, 3000], [$brigadeiro, 1, 30], [$docinhoNinho, 1, 30],
                  [$copoMorango, 1, 6], [$copoWafer, 1, 6], [$boloGelado, 1, 6], [$refriVenda, 12, 6]] as [$id, $batches, $threshold]) {
            $stock = (float) $pdo->query("SELECT stock FROM recipes WHERE id = {$id}")->fetchColumn();
            if ($stock < $threshold) {
                try {
                    $inventory->produce($id, $batches, $date);
                } catch (Throwable) {
                    // sem estoque: segue o dia
                }
            }
        }
        foreach ([[$brigadeiro, mt_rand(15, 40), 400], [$docinhoNinho, mt_rand(10, 30), 450], [$copoMorango, mt_rand(3, 9), 2000],
                  [$copoWafer, mt_rand(2, 7), 2000], [$boloGelado, mt_rand(3, 8), 1600], [$refriVenda, mt_rand(2, 8), 600]] as [$id, $q, $price]) {
            $stock = (float) $pdo->query("SELECT stock FROM recipes WHERE id = {$id}")->fetchColumn();
            $q = min($q, (int) $stock);
            if ($q > 0) {
                $inventory->sell($id, $q, $q * $price, $date);
            }
        }
        if ($ago % 10 === 0) {
            $pdo->prepare('INSERT INTO expenses (description, category, total_cents, spent_on) VALUES (?, ?, ?, ?)')->execute(['Gás de cozinha', 'Operacional', 22000, $date]);
        }
        if ($ago === 25) {
            $pdo->prepare('INSERT INTO expenses (description, category, total_cents, spent_on) VALUES (?, ?, ?, ?)')->execute(['Energia elétrica', 'Operacional', 68000, $date]);
            $pdo->prepare('INSERT INTO expenses (description, category, total_cents, spent_on) VALUES (?, ?, ?, ?)')->execute(['Contador', 'Outros', 30000, $date]);
        }
        if ($ago === 15) {
            $pdo->prepare('INSERT INTO expenses (description, category, total_cents, spent_on) VALUES (?, ?, ?, ?)')->execute(['Impulsionamento no Instagram', 'Marketing', 30000, $date]);
        }
    }
    echo "✔ 30 dias de dados de exemplo criados.\n";
}

if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    $email = getenv('ADMIN_EMAIL') ?: 'admin@exemplo.com';
    $password = getenv('ADMIN_PASSWORD') ?: bin2hex(random_bytes(6));
    (new Auth($pdo))->createUser($email, $password);
    echo "✔ Usuário: {$email}\n";
    if (!getenv('ADMIN_PASSWORD')) {
        echo "  Senha gerada: {$password}\n";
    }
}
echo "✔ Pronto.\n";

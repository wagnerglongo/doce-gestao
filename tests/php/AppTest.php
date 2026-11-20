<?php

declare(strict_types=1);

namespace Confeitaria\Tests;

use Confeitaria\App;
use Confeitaria\Service\Auth;

final class AppTest extends TestCase
{
    private function app(array &$session): App
    {
        return new App($this->pdo, $session, dirname(__DIR__, 2) . '/templates', '2026-10-10');
    }

    public function testTudoExigeLogin(): void
    {
        $session = [];
        foreach (['/', '/receitas', '/caixa.csv'] as $path) {
            [$status, $headers] = $this->app($session)->handle('GET', $path);
            $this->assertSame(303, $status, $path);
            $this->assertSame('/login', $headers['Location']);
        }
    }

    public function testLoginEPostSemCsrf(): void
    {
        (new Auth($this->pdo))->createUser('a@b.com', 'certa');
        $session = [];
        $app = $this->app($session);
        $app->handle('GET', '/login');
        [$status] = $app->handle('POST', '/login', [], ['_csrf' => $session['csrf'], 'email' => 'a@b.com', 'password' => 'certa']);
        $this->assertSame(303, $status);
        [$status] = $app->handle('POST', '/despesas', [], ['description' => 'x', 'total' => '1']);
        $this->assertSame(419, $status);
    }

    public function testSalvarReceitaQueCriariaCicloERecusado(): void
    {
        $leite = $this->ingredient('Leite', 'g', 0, 2);
        $massa = $this->recipe('Massa', 1000, [['i', $leite, 500]]);
        $bolo = $this->recipe('Bolo', 8, [['r', $massa, 800]]);

        $session = ['user_id' => 1, 'csrf' => 't'];
        [$status, , $body] = $this->app($session)->handle('POST', '/receitas/' . $massa, [], [
            '_csrf' => 't', 'name' => 'Massa', 'yield' => '1000', 'price' => '0',
            'item' => ["i:{$leite}", "r:{$bolo}"], 'quantity' => ['500', '1'],
        ]);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('receita circular', $body);
        $this->assertSame(1, (int) $this->value('SELECT COUNT(*) FROM recipe_items WHERE recipe_id = ?', [$massa]), 'nada foi gravado');
    }

    public function testExportaCsv(): void
    {
        $session = ['user_id' => 1];
        [$status, $headers, $body] = $this->app($session)->handle('GET', '/caixa.csv', ['de' => '2026-10-01', 'ate' => '2026-10-31']);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/csv', $headers['Content-Type']);
        $this->assertStringContainsString('fluxo-de-caixa-2026-10-01-a-2026-10-31.csv', $headers['Content-Disposition']);
        $this->assertStringContainsString('Data;Tipo', $body);
    }

    public function testPrecificacaoMostraPrecoSugeridoPorCanal(): void
    {
        $leite = $this->ingredient('Leite', 'g', 0, 2);           // 2 centavos/g
        $bolo = $this->recipe('Bolo', 10, [['i', $leite, 1000]]);  // R$ 20,00 a fornada => R$ 2,00/un

        $session = ['user_id' => 1, 'csrf' => 't'];
        $app = $this->app($session);
        [$status, $headers] = $app->handle('POST', '/precificacao', [], [
            '_csrf' => 't', 'hourly_rate' => '30,00', 'monthly_fixed' => '600,00', 'monthly_units' => '600',
            'fee_own_percent' => '0', 'fee_app_percent' => '30', 'profit_percent' => '20',
        ]);
        $this->assertSame(303, $status);
        $this->assertSame('/precificacao', $headers['Location']);

        [$status] = $app->handle('POST', '/receitas/' . $bolo, [], [
            '_csrf' => 't', 'name' => 'Bolo', 'yield' => '10', 'labor_minutes' => '20', 'profit_percent' => '',
            'price' => '6,00', 'app_price' => '', 'sellable' => '1', 'item' => ["i:{$leite}"], 'quantity' => ['1000'],
        ]);
        $this->assertSame(303, $status);

        // R$ 2,00 ingredientes + R$ 1,00 mão de obra + R$ 1,00 fixos = R$ 4,00
        [, , $body] = $app->handle('GET', '/receitas/' . $bolo);
        $this->assertStringContainsString('R$ 5,00', $body, 'delivery: R$ 4,00 ÷ 0,8');
        $this->assertStringContainsString('R$ 8,00', $body, 'iFood: R$ 4,00 ÷ 0,5');
        $this->assertStringContainsString('data-fill="price-app"', $body);

        [, , $list] = $app->handle('GET', '/receitas');
        $this->assertStringContainsString('Sugerido iFood', $list);
    }

    public function testPrecificacaoRecusaTaxaMaisLucroAcimaDe100(): void
    {
        $session = ['user_id' => 1, 'csrf' => 't'];
        [$status, , $body] = $this->app($session)->handle('POST', '/precificacao', [], [
            '_csrf' => 't', 'hourly_rate' => '0', 'monthly_fixed' => '0', 'monthly_units' => '0',
            'fee_own_percent' => '0', 'fee_app_percent' => '85', 'profit_percent' => '20',
        ]);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('abaixo de 100%', $body);
        $this->assertSame(0, (int) $this->value('SELECT COUNT(*) FROM settings'));
    }

    public function testNumerosNoFormatoBrasileiro(): void
    {
        $this->assertSame(1500.5, App::number('1.500,5'));
        $this->assertSame(1500.5, App::number('1500.5'));
        $this->assertSame(0.5, App::number('0,5'));
        $this->assertNull(App::number('abc'));
        $this->assertNull(App::number(''));
    }
}

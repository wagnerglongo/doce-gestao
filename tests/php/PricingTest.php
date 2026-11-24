<?php

declare(strict_types=1);

namespace Confeitaria\Tests;

use Confeitaria\Database;
use Confeitaria\Domain\Pricing;

final class PricingTest extends TestCase
{
    private function pricing(): Pricing
    {
        return new Pricing([
            'hourly_rate_cents' => 3000,   // R$ 30,00 a hora
            'monthly_fixed_cents' => 60000,    // R$ 600,00 por mês
            'monthly_revenue_cents' => 600000, // R$ 6.000,00 de faturamento => fixos são 10% do preço
            'fee_own_percent' => 0,
            'fee_app_percent' => 30,
            'profit_percent' => 20,
        ]);
    }

    public function testCompoeOCustoTotalEOPrecoPorCanal(): void
    {
        // Ingredientes R$ 2,00/un; 60 min de trabalho numa fornada de 30 un => R$ 1,00/un
        $own = $this->pricing()->breakdown(200, 60, 30);
        $this->assertEqualsWithDelta(100, $own['labor'], 0.001);
        $this->assertEqualsWithDelta(10.0, $own['fixed_percent'], 0.001);
        $this->assertSame(429, $own['price'], 'R$ 3,00 ÷ (1 − 10% fixos − 20% lucro)');
        $this->assertEqualsWithDelta(42.9, $own['fixed'], 0.001, '10% do preço');
        $this->assertEqualsWithDelta(86.1, $own['profit'], 0.001, '20% do preço, com o centavo do arredondamento');

        $app = $this->pricing()->breakdown(200, 60, 30, null, 'app');
        $this->assertSame(750, $app['price'], 'R$ 3,00 ÷ (1 − 10% − 30% − 20%)');
        $this->assertEqualsWithDelta(225, $app['fees'], 0.001);
        $this->assertEqualsWithDelta(150, $app['profit'], 0.001, 'continua sobrando 20% do preço');
    }

    public function testCustoFixoProporcionalAoPrecoDoProduto(): void
    {
        // Brigadeiro (custo direto R$ 1,50) x torta (custo direto R$ 60,00), mesmos 10% de fixos
        $brigadeiro = $this->pricing()->breakdown(150, 0, 1);
        $torta = $this->pricing()->breakdown(6000, 0, 1);
        $this->assertEqualsWithDelta(0.1, $brigadeiro['fixed'] / $brigadeiro['price'], 0.001);
        $this->assertEqualsWithDelta(0.1, $torta['fixed'] / $torta['price'], 0.001);
        $this->assertTrue($torta['fixed'] > 35 * $brigadeiro['fixed'], 'a torta absorve muito mais custo fixo');
    }

    public function testImpostosEntramEmTodosOsCanais(): void
    {
        $pricing = new Pricing(['fee_own_percent' => 4, 'fee_app_percent' => 24, 'tax_percent' => 6, 'profit_percent' => 20] + Pricing::DEFAULTS);
        $this->assertSame(10.0, $pricing->feePercent('own'));
        $this->assertSame(30.0, $pricing->feePercent('app'));
        $this->assertSame(800, $pricing->breakdown(400, 0, 1, null, 'app')['price'], 'R$ 4,00 ÷ (1 − 30% − 20%)');
    }

    public function testLucroDaReceitaSobrepoeOPadrao(): void
    {
        $this->assertSame(750, $this->pricing()->breakdown(200, 60, 30, 50)['price'], 'R$ 3,00 ÷ (1 − 10% − 50%)');
    }

    public function testTaxaMaisLucroAcimaDe100PorCentoERecusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pricing()->breakdown(200, 0, 1, 60, 'app');
    }

    public function testLucroRealComPrecoAtual(): void
    {
        $real = $this->pricing()->realProfit(200, 60, 30, 600, 'app');
        $this->assertEqualsWithDelta(60, $real['profit'], 0.001, 'R$ 6,00 − R$ 3,00 direto − R$ 0,60 fixos − R$ 1,80 de comissão');
        $this->assertEqualsWithDelta(10.0, $real['profit_percent'], 0.001);

        $loss = $this->pricing()->realProfit(200, 60, 30, 400, 'app');
        $this->assertTrue($loss['profit'] < 0, 'preço baixo no iFood dá prejuízo');
    }

    public function testSemConfiguracaoUsaSoIngredientesEOLucroPadrao(): void
    {
        $pricing = Pricing::load($this->pdo);
        $b = $pricing->breakdown(400, 120, 10);
        $this->assertEqualsWithDelta(0, $b['labor'] + $b['fixed'] + $b['fees'], 0.001);
        $this->assertSame(500, $b['price'], 'lucro padrão de 20%');
    }

    public function testSalvaECarregaConfiguracao(): void
    {
        Pricing::save($this->pdo, ['fee_app_percent' => 27.5, 'profit_percent' => 25]);
        Pricing::save($this->pdo, ['fee_app_percent' => 23]);
        $pricing = Pricing::load($this->pdo);
        $this->assertSame(23.0, $pricing->feePercent('app'));
        $this->assertSame(25.0, $pricing->setting('profit_percent'));
    }

    public function testMigracaoAcrescentaColunasEmBancoAntigo(): void
    {
        $pdo = Database::connect('sqlite::memory:');
        $pdo->exec('CREATE TABLE recipes (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL, yield DECIMAL(10,3) NOT NULL,
            yield_unit VARCHAR(12) NOT NULL DEFAULT \'un\', price_cents INT NOT NULL DEFAULT 0, stock DECIMAL(14,3) NOT NULL DEFAULT 0, sellable TINYINT NOT NULL DEFAULT 1)');
        $pdo->exec("INSERT INTO recipes (name, yield) VALUES ('Brigadeiro', 50)");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(160) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL)');
        Database::upgrade($pdo);
        Database::upgrade($pdo); // rodar de novo não pode quebrar
        $this->assertNull($pdo->query('SELECT name FROM users')->fetchColumn() ?: null);
        $this->assertSame((string) Database::SCHEMA_VERSION, $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn());
        $row = $pdo->query('SELECT labor_minutes, app_price_cents, profit_percent FROM recipes')->fetch();
        $this->assertEquals(0, $row['labor_minutes']);
        $this->assertEquals(0, $row['app_price_cents']);
        $this->assertNull($row['profit_percent']);
    }
}

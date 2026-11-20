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
            'monthly_fixed_cents' => 60000, // R$ 600,00 por mês
            'monthly_units' => 600,         // => R$ 1,00 de fixo por unidade
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
        $this->assertEqualsWithDelta(100, $own['fixed'], 0.001);
        $this->assertEqualsWithDelta(400, $own['total_cost'], 0.001);
        $this->assertSame(500, $own['price'], 'R$ 4,00 ÷ (1 − 20%)');
        $this->assertEqualsWithDelta(100, $own['profit'], 0.001);

        $app = $this->pricing()->breakdown(200, 60, 30, null, 'app');
        $this->assertSame(800, $app['price'], 'R$ 4,00 ÷ (1 − 30% − 20%)');
        $this->assertEqualsWithDelta(240, $app['fees'], 0.001);
        $this->assertEqualsWithDelta(160, $app['profit'], 0.001, 'continua sobrando 20% do preço');
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
        $this->assertSame(800, $this->pricing()->breakdown(200, 60, 30, 50)['price']);
    }

    public function testTaxaMaisLucroAcimaDe100PorCentoERecusado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pricing()->breakdown(200, 0, 1, 70, 'app');
    }

    public function testLucroRealComPrecoAtual(): void
    {
        $real = $this->pricing()->realProfit(200, 60, 30, 600, 'app');
        $this->assertEqualsWithDelta(20, $real['profit'], 0.001, 'R$ 6,00 − R$ 4,00 de custo − R$ 1,80 de comissão');
        $this->assertEqualsWithDelta(3.3, $real['profit_percent'], 0.001);

        $loss = $this->pricing()->realProfit(200, 60, 30, 450, 'app');
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

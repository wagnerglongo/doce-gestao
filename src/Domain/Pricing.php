<?php

declare(strict_types=1);

namespace Confeitaria\Domain;

use PDO;

/**
 * Precificação completa de um produto, além do custo dos ingredientes:
 *
 *   custo total/un = ingredientes e embalagens
 *                  + mão de obra (minutos da fornada × valor da hora ÷ rendimento)
 *                  + custos fixos (custos fixos do mês ÷ unidades produzidas no mês)
 *
 *   preço sugerido = custo total ÷ (1 − taxas% − lucro%)
 *
 * Cada canal de venda tem a sua taxa (delivery próprio x iFood), então o mesmo
 * produto tem um preço sugerido por canal.
 *
 * As taxas (maquininha, app de entrega) e o lucro são percentuais sobre o preço
 * de venda, por isso entram no divisor: com taxa de 10% e lucro de 20%, o custo
 * total precisa ser 70% do preço.
 *
 * Valores em centavos (float no meio da conta, arredondado só no preço final).
 */
final class Pricing
{
    public const DEFAULTS = [
        'hourly_rate_cents' => 0,     // quanto vale a hora de trabalho
        'monthly_fixed_cents' => 0,   // gás, energia, aluguel, internet, MEI...
        'monthly_units' => 0,         // quantas unidades vende/produz por mês (para ratear os fixos)
        'fee_own_percent' => 0,       // taxa no delivery próprio/balcão (maquininha, Pix com taxa)
        'fee_app_percent' => 0,       // comissão do app de entrega (iFood) + taxa de pagamento dele
        'tax_percent' => 0,           // impostos sobre a venda (Simples Nacional etc.), em todos os canais
        'profit_percent' => 20,       // lucro desejado padrão sobre o preço
    ];

    /** @param array<string, float|int> $settings */
    public function __construct(private readonly array $settings = self::DEFAULTS)
    {
    }

    public static function load(PDO $pdo): self
    {
        $settings = self::DEFAULTS;
        foreach ($pdo->query('SELECT name, value FROM settings')->fetchAll() as $row) {
            if (array_key_exists($row['name'], $settings)) {
                $settings[$row['name']] = (float) $row['value'];
            }
        }
        return new self($settings);
    }

    /** @param array<string, float|int> $settings */
    public static function save(PDO $pdo, array $settings): void
    {
        $delete = $pdo->prepare('DELETE FROM settings WHERE name = ?');
        $insert = $pdo->prepare('INSERT INTO settings (name, value) VALUES (?, ?)');
        foreach (array_intersect_key($settings, self::DEFAULTS) as $name => $value) {
            $delete->execute([$name]);
            $insert->execute([$name, (string) $value]);
        }
    }

    public function setting(string $name): float
    {
        return (float) ($this->settings[$name] ?? self::DEFAULTS[$name]);
    }

    /** Canais de venda: nome => configuração com a taxa do canal. */
    public const CHANNELS = ['own' => ['Delivery próprio', 'fee_own_percent'], 'app' => ['iFood', 'fee_app_percent']];

    /** Valida taxa + lucro: precisam somar menos de 100% para existir um preço. */
    public static function validPercents(float $feePercent, float $profitPercent): bool
    {
        return $feePercent >= 0 && $profitPercent >= 0 && $feePercent + $profitPercent < 100;
    }

    /**
     * Composição do preço de UMA unidade.
     *
     * @return array{ingredients: float, labor: float, fixed: float, total_cost: float,
     *               fee_percent: float, profit_percent: float, price: int, fees: float, profit: float}
     */
    public function breakdown(float $ingredientsUnitCents, float $laborMinutesPerBatch, float $yield, ?float $profitPercent = null, string $channel = 'own'): array
    {
        $profitPercent ??= $this->setting('profit_percent');
        $feePercent = $this->feePercent($channel);
        if (!self::validPercents($feePercent, $profitPercent)) {
            throw new \InvalidArgumentException('Taxas e lucro precisam somar menos de 100%.');
        }

        $labor = $yield > 0 ? $laborMinutesPerBatch / 60 * $this->setting('hourly_rate_cents') / $yield : 0.0;
        $units = $this->setting('monthly_units');
        $fixed = $units > 0 ? $this->setting('monthly_fixed_cents') / $units : 0.0;
        $total = $ingredientsUnitCents + $labor + $fixed;
        $price = (int) ceil(round($total / (1 - ($feePercent + $profitPercent) / 100), 6));

        return $this->composition($ingredientsUnitCents, $labor, $fixed, $price, $feePercent) + ['profit_percent' => $profitPercent];
    }

    /**
     * Quanto sobra de verdade com um preço já definido, descontando todos os custos e taxas.
     *
     * @return array{total_cost: float, fees: float, profit: float, profit_percent: ?float}
     */
    public function realProfit(float $ingredientsUnitCents, float $laborMinutesPerBatch, float $yield, int $priceCents, string $channel = 'own'): array
    {
        $labor = $yield > 0 ? $laborMinutesPerBatch / 60 * $this->setting('hourly_rate_cents') / $yield : 0.0;
        $units = $this->setting('monthly_units');
        $fixed = $units > 0 ? $this->setting('monthly_fixed_cents') / $units : 0.0;
        $c = $this->composition($ingredientsUnitCents, $labor, $fixed, $priceCents, $this->feePercent($channel));
        return [
            'total_cost' => $c['total_cost'],
            'fees' => $c['fees'],
            'profit' => $c['profit'],
            'profit_percent' => $priceCents > 0 ? round($c['profit'] / $priceCents * 100, 1) : null,
        ];
    }

    /** Taxa do canal + impostos sobre a venda: tudo que sai do preço em percentual. */
    public function feePercent(string $channel): float
    {
        return $this->setting(self::CHANNELS[$channel][1] ?? throw new \InvalidArgumentException('Canal inválido.'))
            + $this->setting('tax_percent');
    }

    private function composition(float $ingredients, float $labor, float $fixed, int $price, float $feePercent): array
    {
        $total = $ingredients + $labor + $fixed;
        $fees = $price * $feePercent / 100;
        return [
            'ingredients' => $ingredients,
            'labor' => $labor,
            'fixed' => $fixed,
            'total_cost' => $total,
            'fee_percent' => $feePercent,
            'price' => $price,
            'fees' => $fees,
            'profit' => $price - $total - $fees,
        ];
    }
}

<?php
$err = fn ($k) => isset($errors[$k]) ? '<p class="error">' . e($errors[$k]) . '</p>' : '';
$raw = !empty($values['raw']);
$moneyField = fn ($k) => $raw ? (string) $values[$k] : number_format(((float) $values[$k]) / 100, 2, ',', '.');
$numField = fn ($k) => $raw ? (string) $values[$k] : qty($values[$k]);
?>
<header class="head"><h1>Precificação</h1><p class="muted">O que entra no preço além dos ingredientes. Vale para todas as receitas; cada receita pode ter um lucro diferente.</p></header>
<div class="grid-2 grid-2--form">
  <form method="post" action="/precificacao" class="card stack">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

    <h2>Mão de obra</h2>
    <label class="field">Quanto vale a sua hora de trabalho (R$)<input name="hourly_rate" inputmode="decimal" placeholder="25,00" value="<?= e($moneyField('hourly_rate_cents')) ?>"></label><?= $err('hourly_rate') ?>
    <p class="muted small">Em cada receita você informa quantos minutos leva uma fornada. O sistema divide pelo rendimento.</p>

    <h2>Custos fixos</h2>
    <div class="row">
      <label class="field">Custos fixos do mês (R$)<input name="monthly_fixed" inputmode="decimal" placeholder="850,00" value="<?= e($moneyField('monthly_fixed_cents')) ?>"></label>
      <label class="field">Unidades vendidas por mês<input name="monthly_units" inputmode="decimal" placeholder="600" value="<?= e($numField('monthly_units')) ?>"></label>
    </div><?= $err('monthly_fixed') ?><?= $err('monthly_units') ?>
    <p class="muted small">Gás, energia, água, aluguel, internet, MEI, contador. O total é dividido pelas unidades vendidas no mês, e cada produto paga a sua parte.</p>

    <h2>Taxas por canal de venda</h2>
    <div class="row">
      <label class="field">Delivery próprio (%)<input name="fee_own_percent" inputmode="decimal" placeholder="3" value="<?= e($numField('fee_own_percent')) ?>"></label>
      <label class="field">iFood (%)<input name="fee_app_percent" inputmode="decimal" placeholder="27" value="<?= e($numField('fee_app_percent')) ?>"></label>
    </div><?= $err('fee_own_percent') ?><?= $err('fee_app_percent') ?>
    <p class="muted small">No delivery próprio, a taxa da maquininha ou do link de pagamento. No iFood, a comissão do seu plano somada à taxa de pagamento online.</p>
    <label class="field">Impostos sobre a venda (%)<input name="tax_percent" inputmode="decimal" placeholder="6" value="<?= e($numField('tax_percent')) ?>"></label><?= $err('tax_percent') ?>
    <p class="muted small">Ex.: alíquota do Simples Nacional. Vale para todos os canais. MEI paga valor fixo: deixe 0 e coloque o DAS nos custos fixos.</p>

    <h2>Lucro</h2>
    <label class="field">Lucro desejado padrão (% do preço)<input name="profit_percent" inputmode="decimal" placeholder="20" value="<?= e($numField('profit_percent')) ?>"></label><?= $err('profit_percent') ?>
    <p class="muted small">É o que sobra limpo depois de pagar ingredientes, embalagem, mão de obra, custos fixos e taxas.</p>

    <div class="actions"><button class="button" type="submit">Salvar precificação</button></div>
  </form>

  <aside class="card stack">
    <h2>Seus últimos 30 dias</h2>
    <dl class="facts">
      <dt>Despesas lançadas</dt><dd><?= money($last30['expenses']) ?></dd>
      <dt>Unidades vendidas</dt><dd><?= qty($last30['sold']) ?></dd>
    </dl>
    <p class="muted small">Use como referência para os custos fixos e as unidades por mês.</p>
    <h2>Como o preço é calculado</h2>
    <p class="small">Custo total = ingredientes e embalagens + mão de obra + parte dos custos fixos.</p>
    <p class="small">Preço sugerido = custo total ÷ (1 − taxas e impostos − lucro).</p>
    <p class="muted small">Ex.: com custo de R$ 7,00, iFood a 27% e lucro de 20%, o preço fica R$ 7,00 ÷ 0,53 = R$ 13,21. Assim, depois da comissão, ainda sobram os 20% de lucro.</p>
  </aside>
</div>

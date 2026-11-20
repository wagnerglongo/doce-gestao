<?php
$err = fn ($k) => isset($errors[$k]) ? '<p class="error">' . e($errors[$k]) . '</p>' : '';
$priceField = fn ($form, $db) => $values[$form] ?? (!empty($values[$db]) ? number_format($values[$db] / 100, 2, ',', '.') : '');
$priceValue = $priceField('price', 'price_cents');
$appPriceValue = $priceField('app_price', 'app_price_cents');
$laborValue = $values['labor_minutes'] ?? '';
$laborValue = is_numeric($laborValue) && (float) $laborValue == 0 ? '' : (is_numeric($laborValue) ? qty($laborValue) : $laborValue);
$profitValue = $values['profit_percent'] ?? '';
$profitValue = is_numeric($profitValue) ? qty($profitValue) : $profitValue;
$pct = fn ($v) => str_replace('.', ',', (string) round((float) $v, 1)) . '%';
$rows = $items ?: [['ingredient_id' => null, 'sub_recipe_id' => null, 'quantity' => '']];
?>
<header class="head"><h1><?= $recipe ? e($recipe['name']) : 'Nova receita' ?></h1></header>
<div class="grid-2 grid-2--form">
  <form method="post" action="/receitas<?= $recipe ? '/' . (int) $recipe['id'] : '' ?>" class="card stack">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <label class="field">Nome<input name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100" required></label><?= $err('name') ?>
    <div class="row">
      <label class="field">Uma fornada rende<input name="yield" inputmode="decimal" value="<?= e(isset($values['yield']) && $values['yield'] !== '' ? qty($values['yield']) : '') ?>" required></label>
      <label class="field">Unidade<input name="yield_unit" value="<?= e($values['yield_unit'] ?? 'un') ?>" maxlength="12" placeholder="un, g, fatias"></label>
    </div><?= $err('yield') ?>

    <fieldset class="items">
      <legend>Ingredientes de uma fornada</legend>
      <div id="items">
        <?php foreach ($rows as $row): $ref = $row['ingredient_id'] ? 'i:' . $row['ingredient_id'] : ($row['sub_recipe_id'] ? 'r:' . $row['sub_recipe_id'] : ''); ?>
          <div class="item-row">
            <select name="item[]" aria-label="Ingrediente ou sub-receita">
              <option value="">Escolha…</option>
              <optgroup label="Ingredientes"><?php foreach ($ingredients as $i): ?><option value="i:<?= (int) $i['id'] ?>" data-unit="<?= e($i['unit']) ?>" <?= $ref === 'i:' . $i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach ?></optgroup>
              <?php if ($subRecipes): ?><optgroup label="Sub-receitas"><?php foreach ($subRecipes as $s): ?><option value="r:<?= (int) $s['id'] ?>" <?= $ref === 'r:' . $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e($s['yield_unit']) ?>)</option><?php endforeach ?></optgroup><?php endif ?>
            </select>
            <input name="quantity[]" inputmode="decimal" placeholder="Qtd." aria-label="Quantidade" value="<?= e($row['quantity'] !== '' ? qty($row['quantity']) : '') ?>">
            <button class="icon" type="button" data-remove aria-label="Remover linha">×</button>
          </div>
        <?php endforeach ?>
      </div>
      <button class="button button--ghost" type="button" id="add-item">Adicionar ingrediente</button>
    </fieldset><?= $err('items') ?>

    <div class="row">
      <label class="field">Mão de obra da fornada (min)<input name="labor_minutes" inputmode="decimal" placeholder="90" value="<?= e($laborValue) ?>"></label>
      <label class="field">Lucro desejado (%)<input name="profit_percent" inputmode="decimal" placeholder="<?= e(qty($defaultProfit)) ?> (padrão)" value="<?= e($profitValue) ?>"></label>
    </div><?= $err('labor_minutes') ?><?= $err('profit_percent') ?>
    <div class="row">
      <label class="field">Preço no delivery próprio<input id="price-own" name="price" inputmode="decimal" placeholder="4,50" value="<?= e($priceValue) ?>"></label>
      <label class="field">Preço no iFood<input id="price-app" name="app_price" inputmode="decimal" placeholder="5,90" value="<?= e($appPriceValue) ?>"></label>
    </div><?= $err('price') ?>
    <label class="check"><input type="checkbox" name="sellable" value="1" <?= !empty($values['sellable']) ? 'checked' : '' ?>> Vendido ao cliente</label>
    <p class="muted small">Desmarque "vendido" para receitas-base, como massas e recheios usados em outros produtos. O tempo dessas bases entra na mão de obra do produto final.</p>
    <div class="actions"><button class="button" type="submit">Salvar receita</button><a href="/receitas">Voltar</a></div>
  </form>

  <aside class="card stack">
    <h2>Custo e preço</h2>
    <?php if (!$cost): ?>
      <p class="muted">Salve a receita para ver o custo.</p>
    <?php else: ?>
      <dl class="facts">
        <dt>Custo da fornada</dt><dd><?= money($cost['batch']) ?></dd>
        <dt>Ingredientes e embalagens por <?= e($recipe['yield_unit']) ?></dt><dd><?= money($cost['unit']) ?></dd>
      </dl>
      <?php if ($recipe['sellable']): foreach ($prices as $channel => $p): $s = $p['suggested']; ?>
        <section class="price-box">
          <h3><?= e($p['label']) ?></h3>
          <?php if (!$s): ?>
            <p class="error">A taxa deste canal somada ao lucro passa de 100%. Ajuste em <a href="/precificacao">Precificação</a>.</p>
          <?php else: ?>
            <dl class="facts">
              <dt>Ingredientes e embalagens</dt><dd><?= money($s['ingredients']) ?></dd>
              <dt>Mão de obra</dt><dd><?= money($s['labor']) ?></dd>
              <dt>Custos fixos</dt><dd><?= money($s['fixed']) ?></dd>
              <dt>Taxas e impostos (<?= $pct($s['fee_percent']) ?>)</dt><dd><?= money($s['fees']) ?></dd>
              <dt>Lucro (<?= $pct($s['profit_percent']) ?>)</dt><dd><?= money($s['profit']) ?></dd>
              <dt class="total">Preço sugerido</dt><dd class="total"><?= money($s['price']) ?></dd>
            </dl>
            <button class="button button--ghost" type="button" data-fill="price-<?= e($channel) ?>" data-value="<?= e(number_format($s['price'] / 100, 2, ',', '')) ?>">Usar <?= money($s['price']) ?></button>
          <?php endif ?>
          <?php if ($p['real']): $r = $p['real']; $bad = $r['profit'] < 0; ?>
            <p class="small">Com o preço atual de <?= money($p['current']) ?>, sobram
              <span class="tag <?= $bad ? 'tag--warn' : 'tag--ok' ?>"><?= money($r['profit']) ?> (<?= $pct($r['profit_percent']) ?>)</span> por <?= e($recipe['yield_unit']) ?><?= $bad ? ': você está pagando para vender.' : '.' ?></p>
          <?php endif ?>
        </section>
      <?php endforeach; ?>
        <p class="muted small">Mão de obra, custos fixos e taxas vêm da tela de <a href="/precificacao">Precificação</a>.</p>
      <?php else: ?>
        <p class="muted small">Receita-base: o custo dela entra no produto final que a usa.</p>
      <?php endif ?>
    <?php endif ?>
  </aside>
</div>

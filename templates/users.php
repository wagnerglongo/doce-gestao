<?php $err = fn ($k) => isset($errors[$k]) ? '<p class="error">' . e($errors[$k]) . '</p>' : ''; ?>
<header class="head"><h1>Usuários</h1><p class="muted">Quem pode entrar no sistema. Cada pessoa entra com o próprio e-mail e senha.</p></header>
<?php if (isset($errors['delete'])): ?><p class="alert" role="alert"><?= e($errors['delete']) ?></p><?php endif ?>
<div class="grid-2 grid-2--form">
  <section class="card">
    <table class="table">
      <thead><tr><th>Nome</th><th>E-mail</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e($u['name'] ?? '—') ?><?= (int) $u['id'] === $me ? ' <span class="tag">você</span>' : '' ?></td>
          <td><?= e($u['email']) ?></td>
          <td class="num"><a href="/usuarios/<?= (int) $u['id'] ?>">Editar</a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </section>
  <section class="card stack">
    <h2><?= $editing ? 'Editar ' . e($editing['name'] ?: $editing['email']) : 'Novo usuário' ?></h2>
    <form method="post" action="/usuarios<?= $editing ? '/' . (int) $editing['id'] : '' ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <label class="field">Nome<input name="name" value="<?= e($values['name'] ?? '') ?>" maxlength="100" placeholder="Ex.: Maria"></label><?= $err('name') ?>
      <label class="field">E-mail<input type="email" name="email" value="<?= e($values['email'] ?? '') ?>" maxlength="160" autocomplete="off" required></label><?= $err('email') ?>
      <label class="field"><?= $editing ? 'Nova senha (deixe em branco para manter)' : 'Senha' ?><input type="password" name="password" minlength="<?= (int) $minPassword ?>" autocomplete="new-password" <?= $editing ? '' : 'required' ?>></label><?= $err('password') ?>
      <p class="muted small">Mínimo de <?= (int) $minPassword ?> caracteres.</p>
      <div class="actions"><button class="button" type="submit"><?= $editing ? 'Salvar' : 'Criar usuário' ?></button><?php if ($editing): ?><a href="/usuarios">Cancelar</a><?php endif ?></div>
    </form>
    <?php if ($editing && (int) $editing['id'] !== $me): ?>
      <form method="post" action="/usuarios/<?= (int) $editing['id'] ?>" data-confirm="Excluir este usuário? Ele não vai mais conseguir entrar.">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="acao" value="excluir">
        <button class="button button--ghost" type="submit">Excluir usuário</button>
      </form>
    <?php endif ?>
  </section>
</div>

<?php

declare(strict_types=1);

namespace Confeitaria;

use Confeitaria\Domain\Inventory;
use Confeitaria\Domain\InventoryException;
use Confeitaria\Domain\Pricing;
use Confeitaria\Domain\RecipeCost;
use Confeitaria\Domain\RecipeCycleException;
use Confeitaria\Domain\Reports;
use Confeitaria\Service\Auth;
use Confeitaria\Service\Csrf;
use PDO;

/**
 * Rotas e controladores. Cada ação devolve [status, headers, corpo],
 * o que permite testar o fluxo inteiro sem servidor web.
 */
final class App
{
    private View $view;
    private RecipeCost $costs;
    private Inventory $inventory;
    private Reports $reports;
    /** @var array<string, mixed> */
    private array $session;

    public const UNITS = ['g' => 'gramas (g)', 'ml' => 'mililitros (ml)', 'un' => 'unidades (un)'];
    public const EXPENSE_CATEGORIES = ['Operacional', 'Embalagem', 'Entrega', 'Marketing', 'Equipamento', 'Outros'];

    public function __construct(private readonly PDO $pdo, array &$session, string $templates, private readonly ?string $today = null)
    {
        $this->session = &$session;
        $this->view = new View($templates);
        $this->costs = new RecipeCost($pdo);
        $this->inventory = new Inventory($pdo, $this->costs);
        $this->reports = new Reports($pdo);
    }

    /** @return array{0: int, 1: array<string, string>, 2: string} */
    public function handle(string $method, string $path, array $query = [], array $post = [], string $ip = '0.0.0.0'): array
    {
        $path = rtrim($path, '/') ?: '/';

        if ($path === '/login') {
            return $method === 'POST' ? $this->login($post, $ip) : $this->loginForm();
        }
        if (empty($this->session['user_id'])) {
            return $this->redirect('/login');
        }
        if ($method === 'POST' && !Csrf::check($this->session, $post['_csrf'] ?? null)) {
            return [419, [], 'Sessão expirada. Volte e envie o formulário de novo.'];
        }

        $id = preg_match('#/(\d+)$#', $path, $m) ? (int) $m[1] : null;
        $route = $id ? preg_replace('#/\d+$#', '/{id}', $path) : $path;

        return match ("{$method} {$route}") {
            'GET /' => $this->dashboard(),
            'POST /sair' => $this->logout(),
            'GET /ingredientes' => $this->ingredients(),
            'POST /ingredientes' => $this->saveIngredient(null, $post),
            'GET /ingredientes/{id}' => $this->ingredients($id),
            'POST /ingredientes/{id}' => $this->saveIngredient($id, $post),
            'GET /receitas' => $this->recipes(),
            'GET /receitas/nova' => $this->recipeForm(null),
            'POST /receitas' => $this->saveRecipe(null, $post),
            'GET /receitas/{id}' => $this->recipeForm($id),
            'POST /receitas/{id}' => $this->saveRecipe($id, $post),
            'GET /producao' => $this->production($query),
            'POST /producao' => $this->produce($post),
            'GET /compras' => $this->purchases(),
            'POST /compras' => $this->purchase($post),
            'GET /vendas' => $this->sales(),
            'POST /vendas' => $this->sell($post),
            'GET /despesas' => $this->expenses(),
            'POST /despesas' => $this->saveExpense($post),
            'GET /caixa' => $this->cashFlow($query),
            'GET /caixa.csv' => $this->cashFlowCsv($query),
            'GET /usuarios' => $this->users(),
            'POST /usuarios' => $this->saveUser(null, $post),
            'GET /usuarios/{id}' => $this->users($id),
            'POST /usuarios/{id}' => ($post['acao'] ?? '') === 'excluir' ? $this->deleteUser($id) : $this->saveUser($id, $post),
            'GET /precificacao' => $this->pricing(),
            'POST /precificacao' => $this->savePricing($post),
            default => [404, [], $this->page('not-found', [])[2]],
        };
    }

    /* --- Autenticação ---------------------------------------------------- */

    private function loginForm(?string $error = null): array
    {
        return $this->html($this->view->render('login', ['error' => $error, 'csrf' => Csrf::token($this->session)]));
    }

    private function login(array $post, string $ip): array
    {
        if (!Csrf::check($this->session, $post['_csrf'] ?? null)) {
            return $this->loginForm('Sessão expirada. Tente de novo.');
        }
        $auth = new Auth($this->pdo);
        if ($auth->isLocked($ip)) {
            return $this->loginForm('Muitas tentativas. Espere 15 minutos.');
        }
        $userId = $auth->attempt((string) ($post['email'] ?? ''), (string) ($post['password'] ?? ''), $ip);
        if ($userId === null) {
            return $this->loginForm('E-mail ou senha incorretos.');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->session['user_id'] = $userId;
        return $this->redirect('/');
    }

    private function logout(): array
    {
        $this->session = [];
        return $this->redirect('/login');
    }

    /* --- Painel ---------------------------------------------------------- */

    private function dashboard(): array
    {
        $to = $this->today();
        $from = date('Y-m-d', strtotime($to . ' -29 days'));
        return $this->page('dashboard', [
            'summary' => $this->reports->summary($from, $to),
            'daily' => $this->reports->dailyRevenue($from, $to),
            'top' => $this->reports->topProducts($from, $to),
            'lowStock' => $this->inventory->lowStock(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    /* --- Ingredientes ---------------------------------------------------- */

    private function ingredients(?int $editId = null, array $errors = [], array $values = []): array
    {
        $list = $this->pdo->query('SELECT * FROM ingredients ORDER BY kind, name')->fetchAll();
        $editing = $editId ? $this->find('ingredients', $editId) : null;
        return $this->page('ingredients', [
            'ingredients' => $list,
            'editing' => $editing,
            'values' => $values ?: ($editing ?? ['unit' => 'g', 'kind' => 'ingrediente']),
            'errors' => $errors,
        ]);
    }

    private function saveIngredient(?int $id, array $post): array
    {
        $name = trim((string) ($post['name'] ?? ''));
        $unit = (string) ($post['unit'] ?? '');
        $kind = ($post['kind'] ?? '') === 'embalagem' ? 'embalagem' : 'ingrediente';
        $min = self::number($post['min_stock'] ?? '0');

        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Informe um nome com até 100 caracteres.';
        }
        if (!isset(self::UNITS[$unit])) {
            $errors['unit'] = 'Escolha a unidade.';
        }
        if ($min === null || $min < 0) {
            $errors['min_stock'] = 'Informe um estoque mínimo válido.';
        }
        if ($errors) {
            return $this->ingredients($id, $errors, $post);
        }

        if ($id) {
            $this->pdo->prepare('UPDATE ingredients SET name = ?, unit = ?, kind = ?, min_stock = ? WHERE id = ?')->execute([$name, $unit, $kind, $min, $id]);
        } else {
            $this->pdo->prepare('INSERT INTO ingredients (name, unit, kind, min_stock) VALUES (?, ?, ?, ?)')->execute([$name, $unit, $kind, $min]);
        }
        $this->flash($id ? 'Ingrediente atualizado.' : 'Ingrediente cadastrado. Registre uma compra para dar entrada no estoque.');
        return $this->redirect('/ingredientes');
    }

    /* --- Receitas -------------------------------------------------------- */

    private function recipes(): array
    {
        $list = $this->pdo->query('SELECT * FROM recipes ORDER BY sellable DESC, name')->fetchAll();
        $pricing = Pricing::load($this->pdo);
        foreach ($list as &$recipe) {
            $recipe['suggested'] = [];
            try {
                $recipe['unit_cost'] = $this->costs->unitCost((int) $recipe['id']);
                $recipe['margin'] = RecipeCost::margin($recipe['unit_cost'], (int) $recipe['price_cents']);
                if ($recipe['sellable']) {
                    foreach (array_keys(Pricing::CHANNELS) as $channel) {
                        $recipe['suggested'][$channel] = $this->breakdown($pricing, $recipe, $recipe['unit_cost'], $channel)['price'] ?? null;
                    }
                }
            } catch (\DomainException) {
                $recipe['unit_cost'] = null;
                $recipe['margin'] = null;
            }
        }
        return $this->page('recipes', ['recipes' => $list]);
    }

    private function recipeForm(?int $id, array $errors = [], ?array $values = null): array
    {
        $recipe = $id ? $this->find('recipes', $id) : null;
        if ($id && !$recipe) {
            return [404, [], $this->page('not-found', [])[2]];
        }
        $items = [];
        if ($recipe && $values === null) {
            $stmt = $this->pdo->prepare('SELECT ingredient_id, sub_recipe_id, quantity FROM recipe_items WHERE recipe_id = ?');
            $stmt->execute([$id]);
            $items = $stmt->fetchAll();
        }

        $cost = null;
        $prices = [];
        $pricing = Pricing::load($this->pdo);
        if ($recipe && !$errors) {
            try {
                $cost = ['batch' => $this->costs->batchCost($id), 'unit' => $this->costs->unitCost($id)];
                foreach (Pricing::CHANNELS as $channel => [$label]) {
                    $current = (int) $recipe[$channel === 'app' ? 'app_price_cents' : 'price_cents'];
                    $prices[$channel] = [
                        'label' => $label,
                        'suggested' => $this->breakdown($pricing, $recipe, $cost['unit'], $channel),
                        'current' => $current,
                        'real' => $current ? $pricing->realProfit($cost['unit'], (float) $recipe['labor_minutes'], (float) $recipe['yield'], $current, $channel) : null,
                    ];
                }
            } catch (\DomainException $error) {
                $errors['items'] = $error->getMessage();
            }
        }

        return $this->page('recipe-form', [
            'recipe' => $recipe,
            'values' => $values ?? ($recipe ?? ['yield_unit' => 'un', 'sellable' => 1]),
            'items' => $values['items'] ?? $items,
            'errors' => $errors,
            'cost' => $cost,
            'prices' => $prices,
            'defaultProfit' => $pricing->setting('profit_percent'),
            'ingredients' => $this->pdo->query('SELECT id, name, unit, unit_cost FROM ingredients ORDER BY name')->fetchAll(),
            'subRecipes' => array_values(array_filter(
                $this->pdo->query('SELECT id, name, yield_unit FROM recipes ORDER BY name')->fetchAll(),
                fn ($r) => (int) $r['id'] !== $id,
            )),
        ]);
    }

    private function saveRecipe(?int $id, array $post): array
    {
        $name = trim((string) ($post['name'] ?? ''));
        $yield = self::number($post['yield'] ?? '');
        $yieldUnit = trim((string) ($post['yield_unit'] ?? 'un')) ?: 'un';
        $price = Money::parse((string) ($post['price'] ?? '0') ?: '0') ?? -1;
        $appPrice = Money::parse((string) ($post['app_price'] ?? '0') ?: '0') ?? -1;
        $labor = self::number($post['labor_minutes'] ?? '0') ?? -1;
        $profitText = trim((string) ($post['profit_percent'] ?? ''));
        $profit = $profitText === '' ? null : self::number($profitText);

        // Linhas da receita: "i:12" para ingrediente, "r:3" para sub-receita
        $items = [];
        foreach ((array) ($post['item'] ?? []) as $i => $ref) {
            $quantity = self::number($post['quantity'][$i] ?? '');
            if (!preg_match('/^([ir]):(\d+)$/', (string) $ref, $m) || !$quantity || $quantity <= 0) {
                continue;
            }
            $items[] = [
                'ingredient_id' => $m[1] === 'i' ? (int) $m[2] : null,
                'sub_recipe_id' => $m[1] === 'r' ? (int) $m[2] : null,
                'quantity' => $quantity,
            ];
        }

        $errors = [];
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Informe um nome com até 100 caracteres.';
        }
        if (!$yield || $yield <= 0) {
            $errors['yield'] = 'Informe quanto uma fornada rende (maior que zero).';
        }
        if ($price < 0 || $appPrice < 0) {
            $errors['price'] = 'Informe um preço válido, por exemplo 4,50.';
        }
        if ($labor < 0) {
            $errors['labor_minutes'] = 'Informe os minutos de trabalho da fornada (pode ser 0).';
        }
        if ($profitText !== '' && ($profit === null || !Pricing::validPercents(0, $profit) || $profit + $this->maxFee() >= 100)) {
            $errors['profit_percent'] = 'O lucro, somado aos custos fixos, taxas e impostos do canal, precisa ficar abaixo de 100%.';
        }
        if (!$items) {
            $errors['items'] = 'Adicione pelo menos um ingrediente.';
        }
        foreach ($items as $item) {
            if ($id && $item['sub_recipe_id'] && $this->costs->wouldCreateCycle($id, $item['sub_recipe_id'])) {
                $errors['items'] = 'Essa sub-receita já usa esta receita. Isso criaria uma receita circular.';
            }
        }
        if ($errors) {
            return $this->recipeForm($id, $errors, $post + ['items' => $items]);
        }

        $this->pdo->beginTransaction();
        $data = [$name, $yield, mb_substr($yieldUnit, 0, 12), $price, $appPrice, $labor, $profit, empty($post['sellable']) ? 0 : 1];
        if ($id) {
            $this->pdo->prepare('UPDATE recipes SET name = ?, yield = ?, yield_unit = ?, price_cents = ?, app_price_cents = ?, labor_minutes = ?, profit_percent = ?, sellable = ? WHERE id = ?')->execute([...$data, $id]);
            $this->pdo->prepare('DELETE FROM recipe_items WHERE recipe_id = ?')->execute([$id]);
        } else {
            $this->pdo->prepare('INSERT INTO recipes (name, yield, yield_unit, price_cents, app_price_cents, labor_minutes, profit_percent, sellable) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($data);
            $id = (int) $this->pdo->lastInsertId();
        }
        $insert = $this->pdo->prepare('INSERT INTO recipe_items (recipe_id, ingredient_id, sub_recipe_id, quantity) VALUES (?, ?, ?, ?)');
        foreach ($items as $item) {
            $insert->execute([$id, $item['ingredient_id'], $item['sub_recipe_id'], $item['quantity']]);
        }
        $this->pdo->commit();

        $this->flash('Receita salva. Veja abaixo o custo e o preço sugerido.');
        return $this->redirect('/receitas/' . $id);
    }

    /** Preço sugerido de uma receita num canal, ou null se taxa + lucro passarem de 100%. */
    private function breakdown(Pricing $pricing, array $recipe, float $unitCost, string $channel): ?array
    {
        $profit = $recipe['profit_percent'] === null ? null : (float) $recipe['profit_percent'];
        try {
            return $pricing->breakdown($unitCost, (float) $recipe['labor_minutes'], (float) $recipe['yield'], $profit, $channel);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function maxFee(): float
    {
        $pricing = Pricing::load($this->pdo);
        return max(array_map(fn ($c) => $pricing->deductionsPercent($c), array_keys(Pricing::CHANNELS)));
    }

    /* --- Usuários -------------------------------------------------------- */

    private function users(?int $editId = null, array $errors = [], ?array $values = null): array
    {
        $auth = new Auth($this->pdo);
        $list = $auth->users();
        $editing = null;
        foreach ($list as $user) {
            if ((int) $user['id'] === $editId) {
                $editing = $user;
            }
        }
        if ($editId && !$editing) {
            return [404, [], $this->page('not-found', [])[2]];
        }
        return $this->page('users', [
            'users' => $list,
            'editing' => $editing,
            'values' => $values ?? ($editing ?? []),
            'errors' => $errors,
            'me' => (int) ($this->session['user_id'] ?? 0),
            'minPassword' => Auth::MIN_PASSWORD,
        ]);
    }

    private function saveUser(?int $id, array $post): array
    {
        $auth = new Auth($this->pdo);
        $email = mb_strtolower(trim((string) ($post['email'] ?? '')));
        $name = trim((string) ($post['name'] ?? ''));
        $password = (string) ($post['password'] ?? '');

        $errors = [];
        if ($name !== '' && mb_strlen($name) > 100) {
            $errors['name'] = 'Use um nome com até 100 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
            $errors['email'] = 'Informe um e-mail válido.';
        } elseif ($auth->emailTaken($email, $id)) {
            $errors['email'] = 'Já existe um usuário com esse e-mail.';
        }
        if ((!$id || $password !== '') && mb_strlen($password) < Auth::MIN_PASSWORD) {
            $errors['password'] = sprintf('A senha precisa ter pelo menos %d caracteres.', Auth::MIN_PASSWORD);
        }
        if ($errors) {
            return $this->users($id, $errors, ['email' => $email, 'name' => $name]);
        }

        if ($id) {
            $auth->updateUser($id, $email, $name, $password);
            $this->flash($password !== '' ? 'Usuário atualizado, com senha nova.' : 'Usuário atualizado.');
        } else {
            $auth->createUser($email, $password, $name);
            $this->flash('Usuário criado. Ele já pode entrar com esse e-mail e senha.');
        }
        return $this->redirect('/usuarios');
    }

    private function deleteUser(int $id): array
    {
        $auth = new Auth($this->pdo);
        if ($id === (int) ($this->session['user_id'] ?? 0)) {
            return $this->users($id, ['delete' => 'Você não pode excluir o seu próprio usuário.']);
        }
        if (count($auth->users()) <= 1) {
            return $this->users($id, ['delete' => 'O sistema precisa de pelo menos um usuário.']);
        }
        $auth->deleteUser($id);
        $this->flash('Usuário excluído.');
        return $this->redirect('/usuarios');
    }

    /* --- Precificação ---------------------------------------------------- */

    private function pricing(array $errors = [], ?array $values = null): array
    {
        $pricing = Pricing::load($this->pdo);
        $to = $this->today();
        $from = date('Y-m-d', strtotime($to . ' -29 days'));
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(total_cents), 0) FROM expenses WHERE spent_on BETWEEN ? AND ?');
        $stmt->execute([$from, $to]);
        $expenses = (int) $stmt->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(total_cents), 0) FROM sales WHERE sold_on BETWEEN ? AND ?');
        $stmt->execute([$from, $to]);
        $sold = (int) $stmt->fetchColumn();

        $current = [];
        foreach (array_keys(Pricing::DEFAULTS) as $name) {
            $current[$name] = $pricing->setting($name);
        }
        return $this->page('pricing', [
            'values' => $values ?? $current,
            'errors' => $errors,
            'last30' => ['expenses' => $expenses, 'revenue' => $sold],
            'fixedPercent' => $pricing->fixedPercent(),
        ]);
    }

    private function savePricing(array $post): array
    {
        $hourly = Money::parse((string) ($post['hourly_rate'] ?? '0') ?: '0');
        $fixed = Money::parse((string) ($post['monthly_fixed'] ?? '0') ?: '0');
        $revenue = Money::parse((string) ($post['monthly_revenue'] ?? '0') ?: '0');
        $feeOwn = self::number($post['fee_own_percent'] ?? '0') ?? -1;
        $feeApp = self::number($post['fee_app_percent'] ?? '0') ?? -1;
        $profit = self::number($post['profit_percent'] ?? '') ?? -1;
        $tax = self::number($post['tax_percent'] ?? '0') ?? -1;

        $errors = [];
        if ($hourly === null) {
            $errors['hourly_rate'] = 'Informe o valor da hora, por exemplo 25,00.';
        }
        if ($fixed === null) {
            $errors['monthly_fixed'] = 'Informe os custos fixos do mês, por exemplo 850,00.';
        }
        if ($revenue === null) {
            $errors['monthly_revenue'] = 'Informe o faturamento médio do mês, por exemplo 45.000,00.';
        } elseif ($fixed && !$revenue) {
            $errors['monthly_revenue'] = 'Informe o faturamento médio do mês para calcular o % dos custos fixos.';
        }
        $fixedPercent = $revenue ? (int) $fixed / $revenue * 100 : 0;
        if ($tax < 0 || $tax >= 100) {
            $errors['tax_percent'] = 'Informe os impostos sobre a venda entre 0% e 99% (pode ser 0).';
        }
        foreach (['fee_own_percent' => $feeOwn, 'fee_app_percent' => $feeApp] as $key => $fee) {
            if (!Pricing::validPercents($fee + max($tax, 0) + $fixedPercent, max($profit, 0))) {
                $errors[$key] = 'Custos fixos, taxa, impostos e lucro precisam somar menos de 100% do preço.';
            }
        }
        if ($profit < 0 || $profit >= 100) {
            $errors['profit_percent'] = 'O lucro precisa ficar entre 0% e 99%.';
        }
        if ($errors) {
            return $this->pricing($errors, [
                'hourly_rate_cents' => $post['hourly_rate'] ?? '', 'monthly_fixed_cents' => $post['monthly_fixed'] ?? '',
                'monthly_revenue_cents' => $post['monthly_revenue'] ?? '', 'fee_own_percent' => $post['fee_own_percent'] ?? '',
                'fee_app_percent' => $post['fee_app_percent'] ?? '', 'profit_percent' => $post['profit_percent'] ?? '',
                'tax_percent' => $post['tax_percent'] ?? '',
                'raw' => true,
            ]);
        }

        Pricing::save($this->pdo, [
            'hourly_rate_cents' => $hourly, 'monthly_fixed_cents' => $fixed, 'monthly_revenue_cents' => $revenue,
            'fee_own_percent' => $feeOwn, 'fee_app_percent' => $feeApp, 'profit_percent' => $profit, 'tax_percent' => $tax,
        ]);
        $this->flash('Precificação salva. Os preços sugeridos das receitas já foram recalculados.');
        return $this->redirect('/precificacao');
    }

    /* --- Produção, compras, vendas e despesas ------------------------------ */

    private function production(array $query, ?string $error = null): array
    {
        $recipeId = (int) ($query['receita'] ?? 0);
        $batches = self::number($query['fornadas'] ?? '1') ?: 1;
        return $this->page('production', [
            'recipes' => $this->pdo->query('SELECT id, name, yield, yield_unit FROM recipes ORDER BY name')->fetchAll(),
            'selected' => $recipeId,
            'batches' => $batches,
            'needs' => $recipeId ? $this->inventory->requirements($recipeId, $batches) : [],
            'history' => $this->pdo->query('SELECT p.*, r.name, r.yield_unit FROM productions p JOIN recipes r ON r.id = p.recipe_id ORDER BY p.produced_on DESC, p.id DESC LIMIT 20')->fetchAll(),
            'error' => $error,
            'today' => $this->today(),
        ]);
    }

    private function produce(array $post): array
    {
        try {
            $result = $this->inventory->produce((int) ($post['recipe_id'] ?? 0), self::number($post['batches'] ?? '') ?? 0, $this->date($post['date'] ?? ''));
            $this->flash(sprintf('Produção registrada: %s unidades, custo de %s.', qty($result['units']), money($result['cost_cents'])));
            return $this->redirect('/producao');
        } catch (InventoryException | RecipeCycleException $error) {
            return $this->production(['receita' => $post['recipe_id'] ?? 0, 'fornadas' => $post['batches'] ?? 1], $error->getMessage());
        }
    }

    private function purchases(?string $error = null): array
    {
        return $this->page('purchases', [
            'ingredients' => $this->pdo->query('SELECT id, name, unit FROM ingredients ORDER BY name')->fetchAll(),
            'history' => $this->pdo->query('SELECT p.*, i.name, i.unit FROM purchases p JOIN ingredients i ON i.id = p.ingredient_id ORDER BY p.purchased_on DESC, p.id DESC LIMIT 30')->fetchAll(),
            'error' => $error,
            'today' => $this->today(),
        ]);
    }

    private function purchase(array $post): array
    {
        try {
            $this->inventory->purchase(
                (int) ($post['ingredient_id'] ?? 0),
                self::number($post['quantity'] ?? '') ?? 0,
                Money::parse((string) ($post['total'] ?? '')) ?? 0,
                $this->date($post['date'] ?? ''),
                trim((string) ($post['supplier'] ?? '')) ?: null,
            );
            $this->flash('Compra registrada. Estoque e custo médio atualizados.');
            return $this->redirect('/compras');
        } catch (InventoryException $error) {
            return $this->purchases($error->getMessage());
        }
    }

    private function sales(?string $error = null): array
    {
        return $this->page('sales', [
            'recipes' => $this->pdo->query('SELECT id, name, price_cents, stock, yield_unit FROM recipes WHERE sellable = 1 ORDER BY name')->fetchAll(),
            'history' => $this->pdo->query('SELECT s.*, r.name FROM sales s JOIN recipes r ON r.id = s.recipe_id ORDER BY s.sold_on DESC, s.id DESC LIMIT 30')->fetchAll(),
            'error' => $error,
            'today' => $this->today(),
        ]);
    }

    private function sell(array $post): array
    {
        try {
            $recipeId = (int) ($post['recipe_id'] ?? 0);
            $quantity = self::number($post['quantity'] ?? '') ?? 0;
            $total = trim((string) ($post['total'] ?? '')) === ''
                ? (int) round(((int) ($this->find('recipes', $recipeId)['price_cents'] ?? 0)) * $quantity)
                : (Money::parse((string) $post['total']) ?? -1);
            $this->inventory->sell($recipeId, $quantity, $total, $this->date($post['date'] ?? ''), trim((string) ($post['customer'] ?? '')) ?: null);
            $this->flash('Venda registrada.');
            return $this->redirect('/vendas');
        } catch (InventoryException | RecipeCycleException $error) {
            return $this->sales($error->getMessage());
        }
    }

    private function expenses(?string $error = null): array
    {
        return $this->page('expenses', [
            'history' => $this->pdo->query('SELECT * FROM expenses ORDER BY spent_on DESC, id DESC LIMIT 30')->fetchAll(),
            'categories' => self::EXPENSE_CATEGORIES,
            'error' => $error,
            'today' => $this->today(),
        ]);
    }

    private function saveExpense(array $post): array
    {
        $description = trim((string) ($post['description'] ?? ''));
        $category = in_array($post['category'] ?? '', self::EXPENSE_CATEGORIES, true) ? $post['category'] : 'Outros';
        $total = Money::parse((string) ($post['total'] ?? ''));
        if ($description === '' || mb_strlen($description) > 120 || !$total) {
            return $this->expenses('Informe a descrição e um valor válido.');
        }
        $this->pdo->prepare('INSERT INTO expenses (description, category, total_cents, spent_on) VALUES (?, ?, ?, ?)')
            ->execute([$description, $category, $total, $this->date($post['date'] ?? '')]);
        $this->flash('Despesa registrada.');
        return $this->redirect('/despesas');
    }

    /* --- Caixa ----------------------------------------------------------- */

    private function period(array $query): array
    {
        $to = $this->validDate($query['ate'] ?? '') ?? $this->today();
        $from = $this->validDate($query['de'] ?? '') ?? date('Y-m-01', strtotime($to));
        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function cashFlow(array $query): array
    {
        [$from, $to] = $this->period($query);
        return $this->page('cashflow', ['flow' => $this->reports->cashFlow($from, $to), 'from' => $from, 'to' => $to]);
    }

    private function cashFlowCsv(array $query): array
    {
        [$from, $to] = $this->period($query);
        return [200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"fluxo-de-caixa-{$from}-a-{$to}.csv\"",
        ], $this->reports->cashFlowCsv($from, $to)];
    }

    /* --- Utilitários ----------------------------------------------------- */

    /** "1.500,5" ou "1500.5" → 1500.5 */
    public static function number(mixed $value): ?float
    {
        $text = trim(str_replace(' ', '', (string) $value));
        if ($text === '') {
            return null;
        }
        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }
        return is_numeric($text) ? (float) $text : null;
    }

    private function today(): string
    {
        return $this->today ?? date('Y-m-d');
    }

    private function validDate(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function date(string $value): string
    {
        return $this->validDate($value) ?? $this->today();
    }

    private function find(string $table, int $id): ?array
    {
        $table = in_array($table, ['ingredients', 'recipes'], true) ? $table : throw new \LogicException('Tabela inválida');
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function page(string $template, array $data): array
    {
        $flash = $this->session['flash'] ?? null;
        unset($this->session['flash']);
        return $this->html($this->view->render($template, $data + [
            'csrf' => Csrf::token($this->session),
            'flash' => $flash,
            'active' => $template,
        ], 'layout'));
    }

    private function flash(string $message): void
    {
        $this->session['flash'] = $message;
    }

    private function html(string $body): array
    {
        return [200, ['Content-Type' => 'text/html; charset=utf-8'], $body];
    }

    private function redirect(string $to): array
    {
        return [303, ['Location' => $to], ''];
    }
}

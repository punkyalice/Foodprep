<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\ContainerRepository;
use App\ContainerTypeRepository;
use App\Db;
use App\InventoryRepository;
use App\ItemTypeDefaultRepository;
use App\MealSetRepository;
use App\RecipeRepository;
use App\SetRepository;
use App\Http\Api\KcalEstimateController;
use App\Http\Api\RecipeController;
use App\Http\Api\SetController;
use App\I18n;

$pdo = Db::pdo();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$locale = detectLocale();
$i18n = new I18n($locale);

if ($path === '/health') {
    echo json_encode(healthCheck(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (str_starts_with($path, '/api/')) {
    handleApi($method, $path, $pdo, $i18n);
    exit;
}

renderPage($path, $locale);

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function healthCheck(): array
{
    $started = microtime(true);

    $dbOk = false;
    $dbError = null;

    try {
        $pdo = Db::pdo();
        $pdo->query('SELECT 1')->fetch();
        $dbOk = true;
    } catch (Throwable $e) {
        $dbOk = false;
        $dbError = $e->getMessage();
    }

    return [
        'ok' => $dbOk,
        'service' => 'freezer-inventory',
        'env' => Config::env('APP_ENV', 'dev'),
        'time' => date('c'),
        'db' => [
            'ok' => $dbOk,
            'host' => Config::env('DB_HOST', 'mysql'),
            'name' => Config::env('DB_NAME', 'freezer_inventory'),
            'error' => $dbError,
        ],
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}

function handleApi(string $method, string $path, PDO $pdo, I18n $i18n): void
{
    $mealRepo = new MealSetRepository($pdo);
    $inventoryRepo = new InventoryRepository($pdo);
    $containerTypeRepo = new ContainerTypeRepository($pdo);
    $containerRepo = new ContainerRepository($pdo);
    $itemTypeRepo = new ItemTypeDefaultRepository($pdo);
    $recipeRepo = new RecipeRepository($pdo);
    $setRepo = new SetRepository($pdo);
    $kcalController = new KcalEstimateController();
    $recipeController = new RecipeController($recipeRepo, i18n: $i18n);
    $setController = new SetController($setRepo, $inventoryRepo, $containerRepo, $i18n);

    if ($recipeController->handle($method, $path)) {
        return;
    }

    if ($kcalController->handle($method, $path)) {
        return;
    }

    if ($setController->handle($method, $path)) {
        return;
    }

    if ($method === 'GET' && $path === '/api/meal_sets') {
        $filters = collectFilters($_GET ?? []);
        $limit = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $data = $mealRepo->listSets($filters, $limit, $offset);
        jsonResponse(['items' => $data]);
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/meal_sets/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $filters = collectFilters($_GET ?? []);
        $set = $mealRepo->getSet($id, $filters);
        if (!$set) {
            jsonResponse(['error' => 'not_found'], 404);
            return;
        }
        jsonResponse($set);
        return;
    }

    if ($method === 'POST' && preg_match('#^/api/meal_sets/(\d+)/takeout$#', $path, $m)) {
        $id = (int)$m[1];
        $body = readJson();
        $itemIds = $body['item_ids'] ?? null;
        if (empty($itemIds)) {
            $itemIds = $mealRepo->chooseFifoItemsForSingleSet($id);
        }

        if (empty($itemIds)) {
            jsonResponse(['error' => 'no_items_available'], 422);
            return;
        }

        try {
            $changed = $inventoryRepo->takeoutItems($itemIds);
            jsonResponse(['ok' => true, 'item_ids' => $changed]);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'GET' && $path === '/api/inventory') {
        $filters = collectFilters($_GET ?? []);
        $limit = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);
        $view = $_GET['view'] ?? 'single';
        $data = $inventoryRepo->listItems($view, $filters, $limit, $offset);
        jsonResponse(['items' => $data]);
        return;
    }

    if ($method === 'POST' && $path === '/api/inventory') {
        try {
            $payload = readJson();
            $created = $inventoryRepo->createItem($payload);
            jsonResponse($created, 201);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'POST' && $path === '/api/inventory/takeout') {
        $body = readJson();
        $ids = $body['item_ids'] ?? [];
        if (empty($ids)) {
            jsonResponse(['error' => 'no_items_selected'], 422);
            return;
        }

        try {
            $changed = $inventoryRepo->takeoutItems($ids);
            jsonResponse(['ok' => true, 'item_ids' => $changed]);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'GET' && $path === '/api/container-types') {
        $items = $containerTypeRepo->listTypes();
        jsonResponse(['ok' => true, 'items' => $items]);
        return;
    }

    if ($method === 'GET' && $path === '/api/item-type-defaults') {
        $items = $itemTypeRepo->listDefaults();
        jsonResponse(['ok' => true, 'items' => $items]);
        return;
    }

    if ($method === 'POST' && $path === '/api/container-types') {
        try {
            $payload = readJson();
            $id = $containerTypeRepo->create($payload);
            jsonResponse(['ok' => true, 'id' => $id], 201);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'GET' && $path === '/api/containers') {
        $active = (string)($_GET['active'] ?? '1');
        $free = (string)($_GET['free'] ?? '');
        if (!in_array($active, ['1', '0', 'all'], true)) {
            $active = '1';
        }
        if ($free === '1') {
            $items = $containerRepo->listFreeContainers();
        } else {
            $items = $containerRepo->listContainers($active);
        }
        jsonResponse(['ok' => true, 'items' => $items]);
        return;
    }

    if ($method === 'POST' && $path === '/api/containers') {
        try {
            $payload = readJson();
            $id = $containerRepo->create($payload);
            jsonResponse(['ok' => true, 'id' => $id], 201);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'PATCH' && preg_match('#^/api/containers/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        try {
            $payload = readJson();
            $containerRepo->update($id, $payload);
            jsonResponse(['ok' => true]);
        } catch (RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
        return;
    }

    if ($method === 'GET' && $path === '/api/storage-standards') {
        jsonResponse(['ok' => true, 'items' => ['FREE', 'FREEZER_BAG', 'VACUUM_BAG']]);
        return;
    }

    jsonResponse(['error' => 'not_found'], 404);
}

function collectFilters(array $input): array
{
    return [
        'q' => trim((string)($input['q'] ?? '')),
        'veggie' => !empty($input['veggie']),
        'expiring' => !empty($input['expiring']),
    ];
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function detectLocale(): string
{
    $supported = ['de', 'en'];
    $query = $_GET['lang'] ?? null;
    if (is_string($query)) {
        $lang = substr(strtolower($query), 0, 2);
        if (in_array($lang, $supported, true)) {
            return $lang;
        }
    }

    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    if ($accept) {
        $parts = explode(',', $accept);
        foreach ($parts as $part) {
            $lang = substr(trim($part), 0, 2);
            if (in_array($lang, $supported, true)) {
                return $lang;
            }
        }
    }

    return 'de';
}

function renderPage(string $path, string $locale): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');

    $normalized = rtrim($path, '/') ?: '/';
    if ($normalized === '/recipes') {
        $page = 'recipes';
    } elseif ($normalized === '/sets') {
        $page = 'sets';
    } elseif (in_array($normalized, ['/containers', '/boxen', '/box-inventar'], true)) {
        $page = 'containers';
    } else {
        $page = 'inventory';
    }
    ?>
<!doctype html>
<html lang="<?= htmlspecialchars($locale) ?>">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Freezer Inventory</title>
    <link rel="stylesheet" href="/assets/css/app.css" />
</head>
<body data-page="<?= htmlspecialchars($page) ?>" data-locale="<?= htmlspecialchars($locale) ?>" data-kcal-enabled="<?= Config::env('OPENAI_API_KEY') ? '1' : '0' ?>">
    <header class="topbar">
        <div class="brand" data-i18n="nav.brand">Freezer Inventory</div>
        <div class="topbar-actions">
            <label for="locale-switcher" class="sr-only" data-i18n="nav.language_label">Sprache</label>
            <select id="locale-switcher" aria-label="Sprache" data-i18n-placeholder="nav.language_label">
                <option value="de">DE</option>
                <option value="en">EN</option>
            </select>
            <div class="menu">
                <button id="burger" aria-label="Menü" data-i18n-aria-label="nav.menu">☰</button>
                <div id="menu-drawer" class="menu-drawer hidden">
                    <a href="/" data-i18n="nav.inventory">Inventar</a>
                    <a href="/containers" data-i18n="nav.containers">Box-Inventar</a>
                    <a href="/sets" data-i18n="nav.sets">Sets</a>
                    <a href="/recipes" data-i18n="nav.recipes">Rezepte</a>
                </div>
            </div>
        </div>
    </header>

    <?php if ($page === 'inventory'): ?>
    <section class="panel">
        <div class="filters">
            <button class="pill" data-filter="view" data-value="meals" aria-pressed="true" data-i18n="inventory.filters.meals">Meals</button>
            <button class="pill" data-filter="view" data-value="single" data-i18n="inventory.filters.single">Einzel</button>
            <button class="pill" data-filter="view" data-value="ingredient" data-i18n="inventory.filters.ingredients">Zutaten</button>
            <button class="pill" data-filter="veggie" data-i18n="inventory.filters.veggie">Veggie</button>
            <button class="pill" data-filter="expiring" data-i18n="inventory.filters.expiring">Läuft bald ab</button>
            <div class="search">
                <input id="search" type="search" placeholder="Suche" data-i18n-placeholder="inventory.search" />
            </div>
        </div>
        <div id="grid" class="card-grid"></div>
    </section>

    <div id="modal" class="modal hidden" role="dialog" aria-modal="true">
        <div class="modal-content">
            <button id="modal-close" class="close" aria-label="×">×</button>
            <div id="modal-body"></div>
        </div>
    </div>
    <?php elseif ($page === 'sets'): ?>
    <main class="sets-page">
        <h1 data-i18n="sets.title">Sets</h1>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 data-i18n="sets.builder.title">Set-Builder</h2>
                    <p class="sub" data-i18n="sets.builder.subtitle">Komponenten planen und reale Boxen packen</p>
                </div>
                <div class="panel-actions">
                    <button id="new-set-btn" class="primary-btn" data-i18n="sets.builder.new">Neues Set</button>
                </div>
            </div>
            <div id="sets-error" class="error-banner hidden"></div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th data-i18n="common.name">Name</th>
                            <th data-i18n="common.note">Notiz</th>
                            <th data-i18n="common.updated">Aktualisiert</th>
                            <th data-i18n="sets.columns.boxes">Boxen</th>
                        </tr>
                    </thead>
                    <tbody id="sets-body">
                        <tr class="empty-row"><td colspan="4" data-i18n="sets.empty">Keine Sets vorhanden.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div id="set-modal" class="modal hidden" role="dialog" aria-modal="true">
            <div class="modal-content wide">
                <button id="set-modal-close" class="close" aria-label="×">×</button>
                <h2 data-i18n="sets.modal.title">Set bauen</h2>
                <div id="set-modal-error" class="error-banner hidden"></div>
                <div class="wizard-steps">
                    <button type="button" class="pill active" data-step-target="1" data-i18n="sets.steps.components">1. Komponenten</button>
                    <button type="button" class="pill" data-step-target="2" data-i18n="sets.steps.boxes">2. Boxen</button>
                </div>

                <div id="set-step-1" class="wizard-step">
                    <form id="set-form" class="form-grid set-form-grid">
                        <label class="wide" data-i18n="common.name">Name
                            <input type="text" name="name" maxlength="200" required />
                        </label>
                        <label class="wide" data-i18n="common.note">Notiz
                            <textarea name="note" rows="2" maxlength="500"></textarea>
                        </label>
                    </form>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-i18n="common.type">Typ</th>
                                    <th data-i18n="common.source">Quelle</th>
                                    <th data-i18n="sets.columns.amount">Menge</th>
                                    <th data-i18n="sets.columns.kcal_total">kcal gesamt</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="components-body">
                                <tr class="empty-row"><td colspan="5" data-i18n="sets.components.empty">Keine Komponenten hinzugefügt.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <form id="component-form" class="component-form">
                        <label data-i18n="common.type">Typ
                            <select name="component_type">
                                <option value="MEAL" data-i18n="types.meal">Meal</option>
                                <option value="PROTEIN" data-i18n="types.protein">Protein</option>
                                <option value="SAUCE" data-i18n="types.sauce">Sauce</option>
                                <option value="SIDE" data-i18n="types.side">Beilage</option>
                                <option value="BASE" data-i18n="types.base">Base</option>
                                <option value="BREAKFAST" data-i18n="types.breakfast">Frühstück</option>
                                <option value="DESSERT" data-i18n="types.dessert">Dessert</option>
                                <option value="MISC" data-i18n="types.misc">Misc</option>
                            </select>
                        </label>
                        <label data-i18n="common.source">Quelle
                            <select name="source_type" id="component-source">
                                <option value="RECIPE" data-i18n="sets.sources.recipe">Rezept</option>
                                <option value="FREE" data-i18n="sets.sources.free">Freitext</option>
                            </select>
                        </label>
                        <label id="component-recipe-field" data-i18n="sets.sources.recipe">Rezept
                            <select name="recipe_id" id="component-recipe"></select>
                        </label>
                        <label id="component-free-field" class="hidden" data-i18n="sets.sources.free">Freitext
                            <input type="text" name="free_text" maxlength="255" />
                        </label>
                        <label data-i18n="sets.columns.amount">Menge
                            <input type="text" name="amount_text" maxlength="100" />
                        </label>
                        <label data-i18n="sets.columns.kcal_total">Kcal gesamt
                            <input type="number" name="kcal_total" min="0" />
                        </label>
                        <div class="wizard-actions">
                            <button type="button" id="add-component-btn" class="secondary-btn" data-i18n="sets.components.add">Komponente hinzufügen</button>
                        </div>
                    </form>
                    <div class="wizard-actions">
                        <button type="button" id="step1-next" class="primary-btn" disabled data-i18n="common.next">Weiter</button>
                    </div>
                </div>

                <div id="set-step-2" class="wizard-step hidden">
                    <p data-i18n="sets.free_containers">Freie Container: <span id="free-container-count">–</span></p>
                    <form id="box-form" class="box-form-grid">
                        <label data-i18n="sets.box.container">Container
                            <select name="container_id" id="box-container"></select>
                        </label>
                        <label data-i18n="sets.box.box_type">Box-Typ
                            <select name="box_type" id="box-type"></select>
                        </label>
                        <label data-i18n="sets.box.portion_factor">Portion-Faktor
                            <input type="number" step="0.1" min="0" name="portion_factor" />
                        </label>
                        <label data-i18n="sets.box.portion_text">Portion-Text
                            <input type="text" name="portion_text" maxlength="50" />
                        </label>
                        <label data-i18n="sets.box.components">Komponenten
                            <div id="component-checklist"></div>
                        </label>
                        <div class="wizard-actions">
                            <button type="button" id="add-box-btn" class="secondary-btn" data-i18n="sets.box.add">Box hinzufügen</button>
                        </div>
                    </form>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th data-i18n="common.type">Typ</th>
                                    <th data-i18n="sets.box.container">Container</th>
                                    <th data-i18n="sets.box.portions">Portionen</th>
                                    <th data-i18n="sets.box.components">Komponenten</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="boxes-body">
                                <tr class="empty-row"><td colspan="5" data-i18n="sets.box.empty">Noch keine Boxen hinzugefügt.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="wizard-actions">
                        <button type="button" id="back-to-components" class="secondary-btn" data-i18n="common.back">Zurück</button>
                        <button type="button" id="pack-set-btn" class="primary-btn" data-i18n="common.pack">Packen</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php elseif ($page === 'recipes'): ?>
    <main class="recipes-page">
        <h1 data-i18n="recipes.title">Rezepte</h1>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 data-i18n="recipes.search.title">Suche &amp; Filter</h2>
                    <p class="sub" data-i18n="recipes.search.subtitle">Rezepte nach Typ und Ernährungsform durchsuchen</p>
                </div>
                <div class="panel-actions">
                    <button id="new-recipe-btn" class="primary-btn" data-i18n="recipes.new">Neues Rezept</button>
                </div>
            </div>
            <div class="filters recipes-filters">
                <div class="search">
                    <input id="recipe-search" type="search" placeholder="Suche nach Name, Zutaten, Tags" data-i18n-placeholder="recipes.search.placeholder" />
                </div>
                <label data-i18n="common.type">Type
                    <select id="recipe-type-filter">
                        <option value="" data-i18n="common.all">Alle</option>
                        <option value="MEAL" data-i18n="types.meal">Meal</option>
                        <option value="PROTEIN" data-i18n="types.protein">Protein</option>
                        <option value="SAUCE" data-i18n="types.sauce">Sauce</option>
                        <option value="SIDE" data-i18n="types.side">Beilage</option>
                        <option value="BASE" data-i18n="types.base">Base</option>
                        <option value="BREAKFAST" data-i18n="types.breakfast">Frühstück</option>
                        <option value="DESSERT" data-i18n="types.dessert">Dessert</option>
                        <option value="MISC" data-i18n="types.misc">Misc</option>
                    </select>
                </label>
                <label data-i18n="recipes.sort">Sortierung
                    <select id="recipe-sort">
                        <option value="name" data-i18n="recipes.sort.name">Name</option>
                        <option value="updated" data-i18n="recipes.sort.updated">Letzte Änderung</option>
                    </select>
                </label>
                <label class="checkbox">
                    <input type="checkbox" id="recipe-veggie-filter" /> <span data-i18n="flags.veggie">Veggie</span>
                </label>
                <label class="checkbox">
                    <input type="checkbox" id="recipe-vegan-filter" /> <span data-i18n="flags.vegan">Vegan</span>
                </label>
            </div>
            <div id="recipe-error" class="error-banner hidden"></div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th data-i18n="common.name">Name</th>
                            <th data-i18n="common.type">Typ</th>
                            <th data-i18n="recipes.flags">Flags</th>
                            <th data-i18n="recipes.portions">Portionen</th>
                            <th data-i18n="recipes.kcal">kcal/Portion</th>
                            <th data-i18n="common.updated">Aktualisiert</th>
                        </tr>
                    </thead>
                    <tbody id="recipes-body">
                        <tr class="empty-row"><td colspan="6" data-i18n="recipes.empty">Keine Rezepte vorhanden.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div id="recipe-modal" class="modal hidden" role="dialog" aria-modal="true">
            <div class="modal-content">
                <button id="recipe-modal-close" class="close" aria-label="×">×</button>
                <h2 id="recipe-modal-title" data-i18n="recipes.modal.new">Neues Rezept</h2>
                <div id="recipe-modal-error" class="error-banner hidden"></div>
                <form id="recipe-form" class="form-grid recipe-form-grid">
                    <label class="wide" data-i18n="common.name">Name
                        <input type="text" name="name" required maxlength="200" />
                    </label>
                    <label data-i18n="common.type">Typ
                        <select name="recipe_type">
                            <option value="MEAL" data-i18n="types.meal">Meal</option>
                            <option value="PROTEIN" data-i18n="types.protein">Protein</option>
                            <option value="SAUCE" data-i18n="types.sauce">Sauce</option>
                            <option value="SIDE" data-i18n="types.side">Beilage</option>
                            <option value="BASE" data-i18n="types.base">Base</option>
                            <option value="BREAKFAST" data-i18n="types.breakfast">Frühstück</option>
                            <option value="DESSERT" data-i18n="types.dessert">Dessert</option>
                            <option value="MISC" data-i18n="types.misc">Misc</option>
                        </select>
                    </label>
                    <label data-i18n="recipes.portions">Portionen
                        <input type="number" name="yield_portions" min="1" />
                    </label>
                    <label data-i18n="recipes.kcal">Kcal/Portion
                      <input type="number" name="kcal_per_portion" min="0" />
                    </label>
                    <div class="gpt-row">
                      <button type="button" id="kcal-estimate" class="secondary-btn" data-i18n="recipes.kcal_estimate">Kalorien mit ChatGPT schätzen</button>
                    </div>
                    <label data-i18n="recipes.best_before">Haltbarkeit (Tage)
                        <input type="number" name="default_best_before_days" min="0" />
                    </label>
                    <label data-i18n="recipes.tags">Tags (Komma-separiert)
                        <input type="text" name="tags_text" maxlength="200" />
                    </label>
                    <div class="check-row wide">
                        <label class="checkbox">
                           <input type="checkbox" name="is_veggie" /> <span data-i18n="flags.veggie">Veggie</span>
                        </label>
                        <label class="checkbox">
                           <input type="checkbox" name="is_vegan" /> <span data-i18n="flags.vegan">Vegan</span>
                        </label>
                    </div>
                    <label class="wide" data-i18n="recipes.ingredients">Zutaten
                        <textarea name="ingredients_text" rows="4" placeholder="500 g Kartoffeln, mehligkochend&#10;1 Ei&#10;Salz, Pfeffer, Muskat&#10;1 EL Sonnenblumenöl"></textarea>
                    </label>
                    <label class="wide" data-i18n="recipes.prep">Zubereitung
                        <textarea name="prep_text" rows="4"></textarea>
                    </label>
                    <label class="wide" data-i18n="recipes.reheat">Auftauen &amp; Aufwärmen
                        <textarea name="reheat_text" rows="3"></textarea>
                    </label>
                    <div class="form-actions wide">
                        <button type="submit" class="primary-btn" data-i18n="common.save">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
    <div id="toast" class="toast hidden" role="status" aria-live="polite"></div>
    <?php else: ?>
    <main class="container-page">
        <h1 data-i18n="containers.title">Box-Inventar</h1>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 data-i18n="containers.types.title">Container Types</h2>
                    <p class="sub" data-i18n="containers.types.subtitle">Form, Volumen und Material je Typ</p>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th data-i18n="containers.columns.shape">Shape</th>
                            <th data-i18n="containers.columns.volume">Vol. (ml)</th>
                            <th data-i18n="containers.columns.dimensions">Maße (mm)</th>
                            <th data-i18n="containers.columns.material">Material</th>
                            <th data-i18n="common.note">Notiz</th>
                        </tr>
                    </thead>
                    <tbody id="container-types-body">
                        <tr class="empty-row"><td colspan="5" data-i18n="containers.types.empty">Keine Typen erfasst.</td></tr>
                    </tbody>
                </table>
            </div>
            <form id="container-type-form" class="inline-form">
                <div class="form-grid">
                    <label data-i18n="containers.columns.shape">Shape
                        <select name="shape" required>
                            <option value="" data-i18n="common.choose">-- wählen --</option>
                            <option value="RECT" data-i18n="containers.shapes.rect">Rechteck</option>
                            <option value="ROUND" data-i18n="containers.shapes.round">Rund</option>
                            <option value="OVAL" data-i18n="containers.shapes.oval">Oval</option>
                        </select>
                    </label>
                    <label data-i18n="containers.columns.volume">Volume (ml)
                        <input type="number" name="volume_ml" min="1" required />
                    </label>
                    <label data-i18n="containers.columns.height">Height (mm)
                        <input type="number" name="height_mm" min="1" />
                    </label>
                    <label data-i18n="containers.columns.width">Width (mm)
                        <input type="number" name="width_mm" min="1" />
                    </label>
                    <label data-i18n="containers.columns.length">Length (mm)
                        <input type="number" name="length_mm" min="1" />
                    </label>
                    <label data-i18n="containers.columns.material">Material
                        <select name="material">
                            <option value="" data-i18n="common.optional">-- optional --</option>
                            <option value="PLASTIC" data-i18n="containers.material.plastic">Plastic</option>
                            <option value="GLASS" data-i18n="containers.material.glass">Glass</option>
                        </select>
                    </label>
                    <label class="wide" data-i18n="common.note">Notiz
                        <input type="text" name="note" maxlength="100" />
                    </label>
                </div>
                <button type="submit" data-i18n="containers.types.new">Neuer Typ</button>
            </form>
        </section>

        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2 data-i18n="containers.list.title">Container</h2>
                    <p class="sub" data-i18n="containers.list.subtitle">Aktive Boxen und Zuordnung zu Typen</p>
                </div>
                <div class="panel-actions">
                    <label data-i18n="common.filter">Filter
                        <select id="container-filter-active">
                            <option value="1" data-i18n="containers.filter.active">Aktiv</option>
                            <option value="0" data-i18n="containers.filter.inactive">Inaktiv</option>
                            <option value="all" data-i18n="containers.filter.all">Alle</option>
                        </select>
                    </label>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th data-i18n="containers.columns.code">Code</th>
                            <th data-i18n="common.type">Typ</th>
                            <th data-i18n="containers.columns.volume">Vol. (ml)</th>
                            <th data-i18n="containers.columns.material">Material</th>
                            <th data-i18n="containers.columns.status">Status</th>
                            <th data-i18n="common.note">Notiz</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="containers-body">
                        <tr class="empty-row"><td colspan="7" data-i18n="containers.list.empty">Keine Container angelegt.</td></tr>
                    </tbody>
                </table>
            </div>
            <form id="container-form" class="inline-form">
                <div class="form-grid">
                    <label data-i18n="containers.columns.code">Code
                        <input type="text" name="container_code" required />
                    </label>
                    <label data-i18n="common.type">Typ
                        <select name="container_type_id" id="container-type-select">
                            <option value="" data-i18n="common.optional">-- optional --</option>
                        </select>
                    </label>
                    <label data-i18n="common.note">Notiz
                        <input type="text" name="note" maxlength="200" />
                    </label>
                    <label class="checkbox">
                        <input type="checkbox" name="is_active" checked /> <span data-i18n="containers.status.active">Aktiv</span>
                    </label>
                </div>
                <button type="submit" data-i18n="containers.list.new">Neue Box</button>
                <p class="muted" data-i18n="containers.hint">Hinweis: Boxen können später einer Inventar-ID zugeordnet werden. Wenn storage_type ≠ BOX, bleibt container_id leer.</p>
            </form>
        </section>
    </main>
    <?php endif; ?>

    <script src="/assets/js/app.js"></script>
</body>
</html>
<?php
}

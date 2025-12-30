const page = document.body.dataset.page || 'inventory';
const initialLocale = document.body.dataset.locale || 'de';
const kcalEnabled = document.body.dataset.kcalEnabled === '1';
const isDev = ['localhost', '127.0.0.1', '0.0.0.0'].includes(window.location.hostname) || window.location.hostname.endsWith('.local');

const LOCALE_STORAGE_KEY = 'locale';

const i18nState = {
    currentLocale: 'de',
    dict: {},
};

const state = {
    view: 'meals',
    veggie: false,
    expiring: false,
    q: '',
};

const containerState = {
    active: '1',
    types: [],
};

const recipeState = {
    search: '',
    type: '',
    veggie: false,
    vegan: false,
    sort: 'name',
    list: [],
    editingId: null,
};

const setState = {
    list: [],
    builder: {
        step: 1,
        setId: null,
        name: '',
        note: '',
        components: [],
        boxes: [],
        containers: [],
        recipes: [],
        boxTypes: [],
    },
};

const componentFormState = {
    kcalAutoValue: null,
    kcalManuallyEdited: false,
};

const KCAL_ESTIMATE_STORAGE_KEY = 'kcal_estimate_last_ts';
const KCAL_CLIENT_COOLDOWN_MS = 60 * 1000;
const KCAL_DEBOUNCE_MS = 2000;
let kcalCooldownTimer = null;

async function initI18n() {
    const locale = resolveLocale();
    await loadLocale(locale);
    initLocaleSwitcher();
}

function resolveLocale() {
    const params = new URLSearchParams(window.location.search);
    const paramLocale = params.get('lang');
    if (paramLocale && ['de', 'en'].includes(paramLocale)) {
        return paramLocale;
    }
    const saved = getSavedLocale();
    if (saved && ['de', 'en'].includes(saved)) {
        return saved;
    }
    return initialLocale || 'de';
}

function getSavedLocale() {
    try {
        return localStorage.getItem(LOCALE_STORAGE_KEY);
    } catch (e) {
        console.warn('Unable to read locale from storage', e);
        return null;
    }
}

function saveLocale(locale) {
    try {
        localStorage.setItem(LOCALE_STORAGE_KEY, locale);
    } catch (e) {
        console.warn('Unable to persist locale selection', e);
    }
}

async function loadLocale(locale) {
    try {
        const res = await fetch(`/assets/i18n/${locale}.json`);
        const dict = await res.json();
        i18nState.currentLocale = ['de', 'en'].includes(locale) ? locale : 'de';
        i18nState.dict = dict || {};
        saveLocale(i18nState.currentLocale);
        document.documentElement.lang = i18nState.currentLocale;
        const switcher = document.getElementById('locale-switcher');
        if (switcher) switcher.value = i18nState.currentLocale;
        applyI18n(document);
    } catch (e) {
        console.error('Failed to load locale', e);
    }
}

function initLocaleSwitcher() {
    const switcher = document.getElementById('locale-switcher');
    if (!switcher) return;
    switcher.value = i18nState.currentLocale;
    switcher.addEventListener('change', async () => {
        const value = switcher.value;
        if (value && ['de', 'en'].includes(value)) {
            await loadLocale(value);
        }
    });
}

function t(key, vars = {}) {
    const template = i18nState.dict[key] || key;
    return template.replace(/\{(.*?)\}/g, (_, name) => (vars && vars[name] !== undefined ? vars[name] : `{${name}}`));
}

function applyI18n(root = document) {
    root.querySelectorAll('[data-i18n]').forEach((el) => {
        const key = el.dataset.i18n;
        if (!key) return;
        const translated = t(key);
        if (el.childElementCount === 0) {
            el.textContent = translated;
        } else {
            let textNode = null;
            for (const node of el.childNodes) {
                if (node.nodeType === Node.TEXT_NODE) {
                    textNode = node;
                    break;
                }
            }
            if (textNode) {
                textNode.textContent = translated + ' ';
            } else {
                el.insertBefore(document.createTextNode(translated + ' '), el.firstChild);
            }
        }
    });

    root.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
        const key = el.dataset.i18nPlaceholder;
        if (!key || !('placeholder' in el)) return;
        el.placeholder = t(key);
    });

    root.querySelectorAll('[data-i18n-aria-label]').forEach((el) => {
        const key = el.dataset.i18nAriaLabel;
        if (!key) return;
        el.setAttribute('aria-label', t(key));
    });
}

document.addEventListener('DOMContentLoaded', async () => {
    await initI18n();
    bindMenu();
    if (page === 'inventory') {
        bindFilters();
        bindModal();
        loadData();
    } else if (page === 'sets') {
        bindSetPage();
    } else if (page === 'recipes') {
        bindRecipePage();
    } else {
        bindContainerForms();
        loadContainerTypes();
        loadContainers();
    }
});

function bindFilters() {
    document.querySelectorAll('.pill').forEach((btn) => {
        btn.addEventListener('click', () => {
            const filter = btn.dataset.filter;
            if (filter === 'view') {
                state.view = btn.dataset.value;
                document.querySelectorAll('button[data-filter="view"]').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
            } else if (filter === 'veggie') {
                state.veggie = !state.veggie;
                btn.classList.toggle('active', state.veggie);
            } else if (filter === 'expiring') {
                state.expiring = !state.expiring;
                btn.classList.toggle('active', state.expiring);
            }
            loadData();
        });
    });

    const search = document.getElementById('search');
    search.addEventListener('input', () => {
        state.q = search.value;
        debounceLoad();
    });

    const defaultView = document.querySelector('button[data-filter="view"][data-value="meals"]');
    if (defaultView) {
        defaultView.classList.add('active');
    }
}

let debounceTimer;
function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadData, 200);
}

function bindMenu() {
    const burger = document.getElementById('burger');
    const drawer = document.getElementById('menu-drawer');
    burger.addEventListener('click', () => {
        drawer.classList.toggle('hidden');
    });
}

function bindModal() {
    const modal = document.getElementById('modal');
    const close = document.getElementById('modal-close');
    close.addEventListener('click', () => modal.classList.add('hidden'));
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.add('hidden');
        }
    });
}

async function loadData() {
    const grid = document.getElementById('grid');
    grid.innerHTML = `<div class="loading" data-i18n="common.loading">${t('common.loading')}</div>`;
    applyI18n(grid);

    try {
        const items = await fetchInventory();
        renderItemCards(items);
    } catch (err) {
        grid.innerHTML = `<div class="error" data-i18n="common.error_loading">${t('common.error_loading')}</div>`;
        applyI18n(grid);
        console.error(err);
    }
}

function buildQuery() {
    const params = new URLSearchParams();
    if (state.q) params.append('q', state.q);
    if (state.veggie) params.append('veggie', '1');
    if (state.expiring) params.append('expiring', '1');
    return params;
}

async function fetchMealSets() {
    const params = buildQuery();
    const res = await fetch('/api/meal_sets?' + params.toString());
    if (!res.ok) throw new Error('load_failed');
    const json = await res.json();
    return json.items || [];
}

async function fetchInventory() {
    const params = buildQuery();
    params.append('view', state.view);
    const res = await fetch('/api/inventory?' + params.toString());
    if (!res.ok) throw new Error('load_failed');
    const json = await res.json();
    return json.items || [];
}

function renderMealCards(items) {
    const grid = document.getElementById('grid');
    grid.innerHTML = '';
    if (!items.length) {
        grid.innerHTML = `<div class="empty" data-i18n="inventory.empty_sets">${t('inventory.empty_sets')}</div>`;
        applyI18n(grid);
        return;
    }

    items.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'card';
        card.innerHTML = `
            <div class="card-title">${item.name}</div>
            <div class="meta">${t('inventory.complete_count', { count: item.complete_count })}</div>
            <div class="flags">${renderFlags(item)}</div>
            <div class="fifo">${(item.fifo_ids || []).join(' + ')}</div>
        `;
        card.addEventListener('click', () => openMealModal(item.id));
        grid.appendChild(card);
    });
}

function renderFlags(item) {
    const flags = [];
    if (item.is_vegan) flags.push(t('flags.vegan_icon'));
    else if (item.is_veggie) flags.push(t('flags.veggie_icon'));
    if (item.is_expiring) flags.push(t('flags.expiring'));
    return flags.join(' · ');
}

function renderItemCards(items) {
    const grid = document.getElementById('grid');
    grid.innerHTML = '';
    if (!items.length) {
        grid.innerHTML = `<div class="empty" data-i18n="inventory.empty_items">${t('inventory.empty_items')}</div>`;
        applyI18n(grid);
        return;
    }

    items.forEach((item) => {
        const card = document.createElement('div');
        card.className = 'card';
        const bestBefore = item.computed_best_before ? item.computed_best_before.substring(0, 10) : '-';
        card.innerHTML = `
            <div class="card-title">${item.name}</div>
            <div class="meta">${t('inventory.item_meta', { id: item.id_code, type: item.item_type })}</div>
            <div class="flags">${item.is_veggie ? '🥕' : ''} ${item.is_vegan ? '🌱' : ''}</div>
            <div class="fifo">${t('inventory.frozen_at', { date: item.frozen_at })} · ${t('inventory.best_before', { date: bestBefore })}</div>
        `;
        card.addEventListener('click', () => openItemModal(item));
        grid.appendChild(card);
    });
}

async function openMealModal(id) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    body.innerHTML = `<div class="loading" data-i18n="common.loading">${t('common.loading')}</div>`;
    modal.classList.remove('hidden');

    try {
        const res = await fetch(`/api/meal_sets/${id}`);
        if (!res.ok) throw new Error('load_failed');
        const data = await res.json();
        const itemsList = (data.items || []).map((it) => `
            <li>
                <strong>${it.id_code}</strong> – ${it.name} (${it.item_type})
                <div class="sub">${t('inventory.frozen_at', { date: it.frozen_at })}, ${t('inventory.best_before', { date: it.computed_best_before?.substring(0, 10) || '-' })}${it.container_code ? `, ${t('inventory.box', { code: it.container_code })}` : ''}</div>
            </li>`).join('');

        body.innerHTML = `
            <h2>${data.name}</h2>
            <div class="flags">${renderFlags(data)}</div>
            <p>${t('inventory.complete_available', { count: data.complete_count })}</p>
            <button class="danger" data-action="takeout" data-id="${data.id}">${t('inventory.takeout')}</button>
            <h3>${t('inventory.fifo_selection')}</h3>
            <ul class="item-list">${itemsList}</ul>
        `;

        const btn = body.querySelector('button[data-action="takeout"]');
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = t('inventory.taking_out');
            try {
                const res = await fetch(`/api/meal_sets/${id}/takeout`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}) });
                const json = await res.json();
                if (!res.ok || json.error) throw new Error(json.error || 'takeout_failed');
                modal.classList.add('hidden');
                loadData();
            } catch (err) {
                btn.disabled = false;
                btn.textContent = t('inventory.takeout');
                alert(t('inventory.takeout_failed'));
            }
        });
    } catch (err) {
        body.innerHTML = `<div class="error" data-i18n="common.error_loading">${t('common.error_loading')}</div>`;
    }
}

function openItemModal(item) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    const bestBefore = item.computed_best_before ? item.computed_best_before.substring(0, 10) : '-';
    body.innerHTML = `
        <h2>${item.name}</h2>
        <div class="meta">${t('inventory.item_meta', { id: item.id_code, type: item.item_type })}</div>
        <p>${t('inventory.frozen_at', { date: item.frozen_at })}<br/>${t('inventory.best_before', { date: bestBefore })}</p>
        <button class="danger" data-action="takeout-item" data-id="${item.id}">${t('inventory.takeout')}</button>
    `;
    modal.classList.remove('hidden');

    const btn = body.querySelector('button[data-action="takeout-item"]');
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = t('inventory.taking_out');
        try {
            const res = await fetch('/api/inventory/takeout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ item_ids: [item.id] }) });
            const json = await res.json();
            if (!res.ok || json.error) throw new Error(json.error || 'takeout_failed');
            modal.classList.add('hidden');
            loadData();
        } catch (err) {
            btn.disabled = false;
            btn.textContent = t('inventory.takeout');
            alert(t('inventory.takeout_failed'));
        }
    });
}

// ----------------------
// Recipes
// ----------------------

function bindRecipePage() {
    const search = document.getElementById('recipe-search');
    const type = document.getElementById('recipe-type-filter');
    const veggie = document.getElementById('recipe-veggie-filter');
    const vegan = document.getElementById('recipe-vegan-filter');
    const sort = document.getElementById('recipe-sort');
    const newBtn = document.getElementById('new-recipe-btn');
    const modal = document.getElementById('recipe-modal');
    const modalClose = document.getElementById('recipe-modal-close');
    const form = document.getElementById('recipe-form');
    const kcalButton = document.getElementById('kcal-estimate');
    const ingredientsField = form?.querySelector('textarea[name="ingredients_text"]');

    if (search) {
        search.addEventListener('input', () => {
            recipeState.search = search.value;
            debounceRecipeLoad();
        });
    }

    if (type) {
        type.addEventListener('change', () => {
            recipeState.type = type.value;
            loadRecipes();
        });
    }

    if (veggie) {
        veggie.addEventListener('change', () => {
            recipeState.veggie = veggie.checked;
            loadRecipes();
        });
    }

    if (vegan) {
        vegan.addEventListener('change', () => {
            recipeState.vegan = vegan.checked;
            loadRecipes();
        });
    }

    if (sort) {
        sort.addEventListener('change', () => {
            recipeState.sort = sort.value;
            loadRecipes();
        });
    }

    if (newBtn) {
        newBtn.addEventListener('click', () => openRecipeModal());
    }

    if (modal && modalClose) {
        modalClose.addEventListener('click', closeRecipeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeRecipeModal();
        });
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            await saveRecipe();
        });
    }

    if (kcalButton) {
        kcalButton.dataset.defaultLabel = kcalButton.textContent;

        if (!kcalEnabled) {
            kcalButton.disabled = true;
            kcalButton.textContent = t('recipes.kcal_estimate_soon');
            setRecipeError(t('recipes.kcal_estimate_soon'), true);
        } else {
            let debounce = false;
            kcalButton.addEventListener('click', async () => {
                if (debounce || kcalButton.disabled) return;
                debounce = true;
                setTimeout(() => {
                    debounce = false;
                }, KCAL_DEBOUNCE_MS);
                await estimateKcal();
            });
        }
    }

    if (ingredientsField) {
        ingredientsField.addEventListener('input', () => updateKcalButtonState());
    }

    updateKcalButtonState();

    loadRecipes();
}

let recipeDebounce;
function debounceRecipeLoad() {
    clearTimeout(recipeDebounce);
    recipeDebounce = setTimeout(loadRecipes, 200);
}

async function loadRecipes() {
    const tbody = document.getElementById('recipes-body');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="6" class="loading" data-i18n="common.loading">${t('common.loading')}</td></tr>`;
    setRecipeError('');

    try {
        const params = new URLSearchParams();
        params.append('limit', '50');
        params.append('offset', '0');
        params.append('sort', recipeState.sort);
        if (recipeState.search) params.append('search', recipeState.search);
        if (recipeState.type) params.append('type', recipeState.type);
        if (recipeState.veggie) params.append('veggie', '1');
        if (recipeState.vegan) params.append('vegan', '1');

        const res = await fetch('/api/recipes?' + params.toString());
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error?.code || 'load_failed');
        recipeState.list = json.data || [];
        renderRecipes(recipeState.list);
    } catch (err) {
        console.error(err);
        setRecipeError(t('recipes.load_error'));
        tbody.innerHTML = `<tr class="empty-row"><td colspan="6" data-i18n="common.error_loading">${t('common.error_loading')}</td></tr>`;
    }
}

function renderRecipes(items) {
    const tbody = document.getElementById('recipes-body');
    if (!tbody) return;

    tbody.innerHTML = '';
    if (!items.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="6" data-i18n="recipes.empty">${t('recipes.empty')}</td></tr>`;
        return;
    }

    items.forEach((item) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${escapeHtml(item.name)}</td>
            <td><span class="badge">${item.recipe_type}</span></td>
            <td>${renderRecipeFlags(item)}</td>
            <td>${item.yield_portions ?? '-'}</td>
            <td>${item.kcal_per_portion ?? '-'}</td>
            <td>${formatDate(item.updated_at || item.created_at)}</td>
        `;
        tr.addEventListener('click', () => openRecipeModal(item));
        tbody.appendChild(tr);
    });
}

function renderRecipeFlags(item) {
    const flags = [];
    if (item.is_vegan) flags.push(t('flags.vegan_icon'));
    else if (item.is_veggie) flags.push(t('flags.veggie_icon'));
    return flags.join(' ');
}

function openRecipeModal(item = null) {
    recipeState.editingId = item?.id || null;
    const modal = document.getElementById('recipe-modal');
    const title = document.getElementById('recipe-modal-title');
    const form = document.getElementById('recipe-form');
    setRecipeError('', true);

    if (!modal || !form || !title) return;

    form.reset();
    if (item) {
        title.textContent = 'Rezept bearbeiten';
        fillRecipeForm(form, item);
    } else {
        title.textContent = 'Neues Rezept';
    }

    modal.classList.remove('hidden');
}

function fillRecipeForm(form, item) {
    form.elements['name'].value = item.name || '';
    form.elements['recipe_type'].value = item.recipe_type || 'MEAL';
    form.elements['yield_portions'].value = item.yield_portions ?? '';
    form.elements['kcal_per_portion'].value = item.kcal_per_portion ?? '';
    form.elements['default_best_before_days'].value = item.default_best_before_days ?? '';
    form.elements['tags_text'].value = item.tags_text || '';
    form.elements['ingredients_text'].value = item.ingredients_text || '';
    form.elements['prep_text'].value = item.prep_text || '';
    form.elements['reheat_text'].value = item.reheat_text || '';
    form.elements['is_veggie'].checked = !!item.is_veggie;
    form.elements['is_vegan'].checked = !!item.is_vegan;
}

function closeRecipeModal() {
    const modal = document.getElementById('recipe-modal');
    if (modal) {
        modal.classList.add('hidden');
    }
    recipeState.editingId = null;
}

async function saveRecipe() {
    const form = document.getElementById('recipe-form');
    if (!form) return;

    const payload = buildRecipePayload(new FormData(form));
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    setRecipeError('', true);

    const method = recipeState.editingId ? 'PATCH' : 'POST';
    const url = recipeState.editingId ? `/api/recipes/${recipeState.editingId}` : '/api/recipes';

    try {
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error?.message || 'save_failed');

        closeRecipeModal();
        showToast(t('common.saved'));
        await loadRecipes();
    } catch (err) {
        console.error(err);
        setRecipeError(t('common.save_failed'), true);
    } finally {
        if (submitBtn) submitBtn.disabled = false;
    }
}

function buildRecipePayload(formData) {
    return {
        name: (formData.get('name') || '').toString().trim(),
        recipe_type: formData.get('recipe_type') || 'MEAL',
        yield_portions: toInt(formData.get('yield_portions')),
        kcal_per_portion: toInt(formData.get('kcal_per_portion')),
        default_best_before_days: toInt(formData.get('default_best_before_days')),
        tags_text: (formData.get('tags_text') || '').toString().trim() || null,
        ingredients_text: (formData.get('ingredients_text') || '').toString().trim() || null,
        prep_text: (formData.get('prep_text') || '').toString().trim() || null,
        reheat_text: (formData.get('reheat_text') || '').toString().trim() || null,
        is_veggie: formData.get('is_veggie') ? 1 : 0,
        is_vegan: formData.get('is_vegan') ? 1 : 0,
    };
}

function setRecipeError(message, inModal = false) {
    const targetId = inModal ? 'recipe-modal-error' : 'recipe-error';
    const el = document.getElementById(targetId);
    if (!el) return;
    if (!message) {
        el.classList.add('hidden');
        el.textContent = '';
    } else {
        el.classList.remove('hidden');
        el.textContent = message;
    }
}

function logDev(...args) {
    if (isDev) {
        console.debug(...args);
    }
}

function parseKcalResponse(raw) {
    if (raw === undefined || raw === null) return null;
    const normalized = String(raw).trim().replace(/[–—]/g, '-');
    const match = normalized.match(/^(\d{1,4})(?:\s*-\s*(\d{1,4}))?$/);
    if (!match) return null;
    const min = parseInt(match[1], 10);
    const max = match[2] ? parseInt(match[2], 10) : min;
    return {
        raw: normalized,
        min,
        max,
        best: Math.max(min, max),
    };
}

async function estimateKcal() {
    const form = document.getElementById('recipe-form');
    const button = document.getElementById('kcal-estimate');
    if (!form || !button) return;

    if (!kcalEnabled) {
        setRecipeError(t('recipes.kcal_estimate_soon'), true);
        return;
    }

    const ingredientsField = form.querySelector('textarea[name="ingredients_text"]');
    const yieldField = form.querySelector('input[name="yield_portions"]');
    const kcalField = form.querySelector('input[name="kcal_per_portion"]');
    const ingredientsText = (ingredientsField?.value || '').toString().trim();
    const yieldPortions = toInt(yieldField?.value) || 1;

    if (!ingredientsText) {
        setRecipeError('Bitte Zutaten eintragen.', true);
        return;
    }

    const cooldown = getLocalKcalCooldownRemaining();
    if (cooldown > 0) {
        showToast(t('recipes.kcal_estimate_wait_minute'));
        updateKcalButtonState();
        return;
    }

    setRecipeError('', true);
    button.disabled = true;
    button.dataset.loading = '1';
    button.textContent = 'Schätze…';
    setLocalKcalTimestamp(Date.now());
    updateKcalButtonState();

    try {
        const res = await fetch('/api/recipes/estimate-kcal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ingredients_text: ingredientsText, yield_portions: yieldPortions }),
        });
        const json = await res.json();
        logDev('kcal estimate response', json);
        if (!res.ok || !json.ok) {
            throw {
                code: json.error || 'estimate_failed',
                retry_after_seconds: json.retry_after_seconds ?? null,
            };
        }

        const rawEstimate = json.kcal_per_portion ?? json.response ?? json.raw ?? json.result ?? json.answer ?? null;
        const parsed = typeof rawEstimate === 'number' ? {
            raw: String(rawEstimate),
            min: rawEstimate,
            max: rawEstimate,
            best: rawEstimate,
        } : parseKcalResponse(rawEstimate);

        if (!parsed) {
            if (isDev) {
                console.error('Kcal estimate could not be parsed', { raw: rawEstimate, payload: json });
            }
            throw { code: 'openai_invalid_response' };
        }

        if (kcalField) {
            if (kcalField.type === 'number') {
                kcalField.value = parsed.best ?? '';
            } else {
                kcalField.value = parsed.raw ?? '';
            }
            ['input', 'change'].forEach((eventName) => kcalField.dispatchEvent(new Event(eventName, { bubbles: true })));
        }
        showToast('Kalorien übernommen');
    } catch (err) {
        const code = err?.code || err?.message || 'estimate_failed';
        const retry = err?.retry_after_seconds ?? null;
        let message = 'Kalorien konnten nicht geschätzt werden.';
        if (code === 'missing_ingredients') {
            message = 'Bitte Zutaten eintragen.';
        } else if (code === 'rate_limited') {
            const waitSeconds = typeof retry === 'number' && retry > 0 ? Math.round(retry) : Math.ceil(KCAL_CLIENT_COOLDOWN_MS / 1000);
            const adjusted = Date.now() - (KCAL_CLIENT_COOLDOWN_MS - waitSeconds * 1000);
            setLocalKcalTimestamp(adjusted);
            message = `Bitte warten ${waitSeconds}s bevor du erneut schätzt.`;
        } else if (code === 'openai_not_configured') {
            message = 'Kalorien-Schätzung ist nicht konfiguriert.';
        } else if (code === 'openai_invalid_response') {
            message = 'Unerwartete Antwort beim Schätzen.';
        } else if (code === 'openai_error') {
            message = 'Fehler bei der Anfrage an den Schätzdienst.';
        }
        setRecipeError(message, true);
    } finally {
        button.dataset.loading = '0';
        updateKcalButtonState();
    }
}

function updateKcalButtonState() {
    const form = document.getElementById('recipe-form');
    const button = document.getElementById('kcal-estimate');
    if (!form || !button) return;

    if (!kcalEnabled) {
        button.disabled = true;
        button.textContent = t('recipes.kcal_estimate_soon');
        return;
    }

    if (!button.dataset.defaultLabel) {
        button.dataset.defaultLabel = button.textContent;
    }
    const baseLabel = button.dataset.defaultLabel;

    if (button.dataset.loading === '1') {
        button.disabled = true;
        button.textContent = 'Schätze…';
        return;
    }

    const ingredientsField = form.querySelector('textarea[name="ingredients_text"]');
    const ingredientsText = (ingredientsField?.value || '').toString();
    const hasIngredients = ingredientsText.trim().length > 0;
    const cooldown = getLocalKcalCooldownRemaining();
    const shouldDisable = !hasIngredients || cooldown > 0;

    button.disabled = shouldDisable;
    if (shouldDisable && cooldown > 0) {
        button.textContent = `Bitte warten (${cooldown}s)`;
    } else {
        button.textContent = baseLabel;
    }

    if (cooldown > 0) {
        if (kcalCooldownTimer) clearTimeout(kcalCooldownTimer);
        kcalCooldownTimer = setTimeout(updateKcalButtonState, 1000);
    } else if (kcalCooldownTimer) {
        clearTimeout(kcalCooldownTimer);
        kcalCooldownTimer = null;
    }
}

function getLocalKcalCooldownRemaining() {
    try {
        const raw = localStorage.getItem(KCAL_ESTIMATE_STORAGE_KEY);
        const last = raw ? parseInt(raw, 10) : NaN;
        if (Number.isNaN(last)) return 0;
        const elapsed = Date.now() - last;
        if (elapsed < KCAL_CLIENT_COOLDOWN_MS) {
            return Math.ceil((KCAL_CLIENT_COOLDOWN_MS - elapsed) / 1000);
        }
    } catch (e) {
        return 0;
    }
    return 0;
}

function setLocalKcalTimestamp(timestampMs) {
    try {
        localStorage.setItem(KCAL_ESTIMATE_STORAGE_KEY, String(timestampMs));
    } catch (e) {
        // ignore storage issues
    }
}

function showToast(message) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.classList.remove('hidden');
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
        toast.classList.add('hidden');
    }, 2000);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    return dateString.substring(0, 10);
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

// ----------------------
// Container management
// ----------------------

function bindContainerForms() {
    const filter = document.getElementById('container-filter-active');
    if (filter) {
        filter.value = containerState.active;
        filter.addEventListener('change', () => {
            containerState.active = filter.value;
            loadContainers();
        });
    }

    const typeForm = document.getElementById('container-type-form');
    if (typeForm) {
        typeForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(typeForm);
            const payload = buildContainerTypePayload(formData);
            try {
                await postJson('/api/container-types', payload);
                typeForm.reset();
                await loadContainerTypes();
                await loadContainers();
            } catch (err) {
                alert('Typ konnte nicht gespeichert werden.');
                console.error(err);
            }
        });
    }

    const containerForm = document.getElementById('container-form');
    if (containerForm) {
        containerForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(containerForm);
            const payload = buildContainerPayload(formData);
            try {
                await postJson('/api/containers', payload);
                containerForm.reset();
                await loadContainers();
            } catch (err) {
                alert('Container konnte nicht angelegt werden.');
                console.error(err);
            }
        });
    }
}

function buildContainerTypePayload(formData) {
    const payload = {
        shape: formData.get('shape'),
        volume_ml: toInt(formData.get('volume_ml')),
        height_mm: toInt(formData.get('height_mm')),
        width_mm: toInt(formData.get('width_mm')),
        length_mm: toInt(formData.get('length_mm')),
        material: formData.get('material') || null,
        note: formData.get('note') || null,
    };
    if (!payload.height_mm) delete payload.height_mm;
    if (!payload.width_mm) delete payload.width_mm;
    if (!payload.length_mm) delete payload.length_mm;
    if (!payload.material) delete payload.material;
    if (!payload.note) delete payload.note;
    return payload;
}

function buildContainerPayload(formData) {
    const typeId = toInt(formData.get('container_type_id'));
    const payload = {
        container_code: (formData.get('container_code') || '').trim(),
        container_type_id: typeId || null,
        note: (formData.get('note') || '').trim() || null,
        is_active: formData.get('is_active') ? 1 : 0,
    };
    if (!payload.container_type_id) delete payload.container_type_id;
    if (!payload.note) delete payload.note;
    return payload;
}

async function loadContainerTypes() {
    const tbody = document.getElementById('container-types-body');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="5" class="loading" data-i18n="common.loading">${t('common.loading')}</td></tr>`;
    try {
        const items = await fetchContainerTypes();
        containerState.types = items;
        renderContainerTypes(items);
        populateContainerTypeSelect(items);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="5" class="error" data-i18n="common.error_loading">${t('common.error_loading')}</td></tr>`;
        console.error(err);
    }
}

function renderContainerTypes(items) {
    const tbody = document.getElementById('container-types-body');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!items.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5" data-i18n="containers.types.empty">${t('containers.types.empty')}</td></tr>`;
        return;
    }

    items.forEach((item) => {
        const tr = document.createElement('tr');
        const dims = [item.length_mm, item.width_mm, item.height_mm].filter(Boolean).join(' × ');
        tr.innerHTML = `
            <td>${item.shape}</td>
            <td>${item.volume_ml}</td>
            <td>${dims || '-'}</td>
            <td>${item.material || '-'}</td>
            <td>${item.note || ''}</td>
        `;
        tbody.appendChild(tr);
    });
}

function populateContainerTypeSelect(items) {
    const select = document.getElementById('container-type-select');
    if (!select) return;
    const current = select.value;
    select.innerHTML = '<option value="">-- optional --</option>';
    items.forEach((item) => {
        const option = document.createElement('option');
        option.value = item.id;
        option.textContent = `${item.shape} · ${item.volume_ml} ml`;
        if (String(item.id) === current) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

async function loadContainers() {
    const tbody = document.getElementById('containers-body');
    if (!tbody) return;
    tbody.innerHTML = `<tr><td colspan="7" class="loading" data-i18n="common.loading">${t('common.loading')}</td></tr>`;
    try {
        const items = await fetchContainers(containerState.active);
        renderContainers(items);
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="error" data-i18n="common.error_loading">${t('common.error_loading')}</td></tr>`;
        console.error(err);
    }
}

function renderContainers(items) {
    const tbody = document.getElementById('containers-body');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (!items.length) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="7" data-i18n="containers.list.empty">${t('containers.list.empty')}</td></tr>`;
        return;
    }

    items.forEach((item) => {
        const tr = document.createElement('tr');
        const typeLabel = item.container_type_id ? `${item.shape || '-'} · ${item.volume_ml || '?'} ml` : '-';
        const status = item.is_active ? t('containers.status.active') : t('containers.status.inactive');
        const buttonLabel = item.is_active ? t('containers.actions.deactivate') : t('containers.actions.reactivate');
        tr.innerHTML = `
            <td>${item.container_code}</td>
            <td>${typeLabel}</td>
            <td>${item.volume_ml || '-'}</td>
            <td>${item.material || '-'}</td>
            <td>${status}</td>
            <td>${item.note || ''}</td>
            <td><button data-action="toggle" data-id="${item.id}" data-active="${item.is_active ? 1 : 0}">${buttonLabel}</button></td>
        `;
        const btn = tr.querySelector('button[data-action="toggle"]');
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            try {
                await updateContainer(item.id, { is_active: item.is_active ? 0 : 1 });
                await loadContainers();
            } catch (err) {
                alert(t('containers.actions.toggle_failed'));
                console.error(err);
                btn.disabled = false;
            }
        });
        tbody.appendChild(tr);
    });
}

async function fetchContainerTypes() {
    const res = await fetch('/api/container-types');
    if (!res.ok) throw new Error('load_failed');
    const json = await res.json();
    return json.items || [];
}

async function fetchContainers(active) {
    const res = await fetch(`/api/containers?active=${encodeURIComponent(active)}`);
    if (!res.ok) throw new Error('load_failed');
    const json = await res.json();
    return json.items || [];
}

async function postJson(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok || json.error) {
        throw new Error(json.error || 'request_failed');
    }
    return json;
}

async function updateContainer(id, payload) {
    const res = await fetch(`/api/containers/${id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const json = await res.json();
    if (!res.ok || json.error) {
        throw new Error(json.error || 'request_failed');
    }
}

function toInt(value) {
    const num = parseInt(value, 10);
    return Number.isNaN(num) ? null : num;
}

// Sets / Wizard
function bindSetPage() {
    loadSets();
    const btn = document.getElementById('new-set-btn');
    btn?.addEventListener('click', openSetModal);
    bindSetModal();
}

function bindSetModal() {
    const modal = document.getElementById('set-modal');
    const close = document.getElementById('set-modal-close');
    close?.addEventListener('click', closeSetModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeSetModal();
    });

    document.getElementById('component-source')?.addEventListener('change', toggleComponentSourceFields);
    document.getElementById('component-recipe')?.addEventListener('change', handleRecipeSelection);
    document.querySelector('#component-form input[name="kcal_total"]')?.addEventListener('input', () => {
        componentFormState.kcalManuallyEdited = true;
    });
    document.getElementById('add-component-btn')?.addEventListener('click', addComponentFromForm);
    document.getElementById('step1-next')?.addEventListener('click', submitSetStep1);
    document.getElementById('back-to-components')?.addEventListener('click', () => switchStep(1));
    document.getElementById('pack-set-btn')?.addEventListener('click', packSet);
    document.getElementById('add-box-btn')?.addEventListener('click', addBoxFromForm);

    const nameInput = document.querySelector('#set-form input[name="name"]');
    nameInput?.addEventListener('input', updateStep1NextState);
}

async function loadSets() {
    try {
        const res = await fetch('/api/sets');
        const json = await res.json();
        setState.list = json.items || [];
        renderSetList();
    } catch (e) {
        showSetError(t('sets.load_error'));
    }
}

function renderSetList() {
    const body = document.getElementById('sets-body');
    if (!body) return;
    body.innerHTML = '';
    if (!setState.list.length) {
        body.innerHTML = `<tr class="empty-row"><td colspan="4" data-i18n="sets.empty">${t('sets.empty')}</td></tr>`;
        return;
    }

    setState.list.forEach((item) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${item.name}</td>
            <td>${item.note ? item.note : ''}</td>
            <td>${item.updated_at ? new Date(item.updated_at).toLocaleDateString() : ''}</td>
            <td>${item.box_count ?? '-'}</td>
        `;
        body.appendChild(tr);
    });
}

function showSetError(message) {
    const el = document.getElementById('sets-error');
    if (!el) return;
    if (!message) {
        el.classList.add('hidden');
        el.textContent = '';
    } else {
        el.classList.remove('hidden');
        el.textContent = message;
    }
}

function openSetModal() {
    resetBuilder();
    const modal = document.getElementById('set-modal');
    modal?.classList.remove('hidden');
    loadRecipesForSets();
    loadItemTypeDefaults();
    toggleComponentSourceFields();
    renderComponentsTable();
    renderBoxesTable();
    updateStep1NextState();
}

function closeSetModal() {
    const modal = document.getElementById('set-modal');
    modal?.classList.add('hidden');
}

function resetBuilder() {
    const existingRecipes = setState.builder.recipes || [];
    const existingBoxTypes = setState.builder.boxTypes || [];
    setState.builder = {
        step: 1,
        setId: null,
        name: '',
        note: '',
        components: [],
        boxes: [],
        containers: [],
        recipes: existingRecipes,
        boxTypes: existingBoxTypes,
    };
    switchStep(1);
    const form = document.getElementById('set-form');
    form?.reset();
    document.getElementById('component-form')?.reset();
    document.getElementById('box-form')?.reset();
    resetComponentFormState();
    const err = document.getElementById('set-modal-error');
    if (err) {
        err.classList.add('hidden');
        err.textContent = '';
    }
}

function resetComponentFormState() {
    componentFormState.kcalAutoValue = null;
    componentFormState.kcalManuallyEdited = false;
}

function toggleComponentSourceFields() {
    const source = document.getElementById('component-source').value;
    const recipeField = document.getElementById('component-recipe-field');
    const freeField = document.getElementById('component-free-field');
    if (source === 'RECIPE') {
        recipeField.classList.remove('hidden');
        freeField.classList.add('hidden');
    } else {
        recipeField.classList.add('hidden');
        freeField.classList.remove('hidden');
    }
}

function getRecipeNameById(id) {
    if (!id) return null;
    const match = (setState.builder.recipes || []).find((r) => String(r.id) === String(id));
    return match ? match.name : null;
}

function formatComponentSource(comp) {
    if (comp.source_type === 'RECIPE') {
        return comp.recipe_name || getRecipeNameById(comp.recipe_id) || (comp.recipe_id ? 'Rezept #' + comp.recipe_id : 'Rezept');
    }
    return comp.free_text;
}

function mapComponentsWithRecipeNames(components) {
    return (components || []).map((comp) => ({
        ...comp,
        recipe_name: comp.recipe_name || getRecipeNameById(comp.recipe_id),
    }));
}

function applyAutoKcalFromRecipe(recipe) {
    const kcalInput = document.querySelector('#component-form input[name="kcal_total"]');
    if (!kcalInput) return;

    const autoValue = recipe && recipe.kcal_per_portion !== null ? recipe.kcal_per_portion : null;
    const currentValue = kcalInput.value;
    const previousAuto = componentFormState.kcalAutoValue;

    const shouldOverwrite = !componentFormState.kcalManuallyEdited
        || currentValue === ''
        || currentValue === String(previousAuto ?? '');

    if (shouldOverwrite) {
        kcalInput.value = autoValue ?? '';
        componentFormState.kcalManuallyEdited = false;
    }

    componentFormState.kcalAutoValue = autoValue;
}

function handleRecipeSelection() {
    const select = document.getElementById('component-recipe');
    if (!select) return;
    const recipeId = select.value;
    const recipe = (setState.builder.recipes || []).find((r) => String(r.id) === String(recipeId));
    applyAutoKcalFromRecipe(recipe || null);
}

function renderComponentsTable() {
    const body = document.getElementById('components-body');
    if (!body) return;
    body.innerHTML = '';
    if (!setState.builder.components.length) {
        body.innerHTML = `<tr class="empty-row"><td colspan="5" data-i18n="sets.components.empty">${t('sets.components.empty')}</td></tr>`;
        return;
    }

    setState.builder.components.forEach((comp, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${comp.component_type}</td>
            <td>${formatComponentSource(comp)}</td>
            <td>${comp.amount_text || ''}</td>
            <td>${comp.kcal_total ?? '-'}</td>
            <td><button type="button" class="link" data-index="${idx}">${t('common.remove')}</button></td>
        `;
        tr.querySelector('button')?.addEventListener('click', () => {
            setState.builder.components.splice(idx, 1);
            renderComponentsTable();
            renderComponentChecklist();
            updateStep1NextState();
        });
        body.appendChild(tr);
    });
}

function addComponentFromForm() {
    const form = document.getElementById('component-form');
    if (!form) return;
    const data = new FormData(form);
    const component = {
        component_type: data.get('component_type'),
        source_type: data.get('source_type'),
        recipe_id: data.get('recipe_id'),
        recipe_name: null,
        free_text: data.get('free_text'),
        amount_text: data.get('amount_text'),
        kcal_total: data.get('kcal_total'),
    };

    if (component.source_type === 'RECIPE' && component.recipe_id) {
        component.recipe_name = getRecipeNameById(component.recipe_id);
    }

    if (!component.component_type || !component.source_type) {
        return;
    }
    if (component.source_type === 'RECIPE' && !component.recipe_id) {
        return;
    }
    if (component.source_type === 'FREE' && !component.free_text) {
        return;
    }
    if (component.source_type === 'FREE' && !component.kcal_total) {
        return;
    }

    setState.builder.components.push(component);
    renderComponentsTable();
    renderComponentChecklist();
    updateStep1NextState();
    form.reset();
    resetComponentFormState();
    toggleComponentSourceFields();
}

async function submitSetStep1() {
    const nameInput = document.querySelector('#set-form input[name="name"]');
    const noteInput = document.querySelector('#set-form textarea[name="note"]');
    if (!nameInput || !noteInput) return;
    setState.builder.name = nameInput.value.trim();
    setState.builder.note = noteInput.value.trim();
    if (!isStep1Valid()) {
        showSetModalError('Bitte Namen und mindestens eine Komponente angeben.');
        return;
    }

    try {
        const res = await fetch('/api/sets', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: setState.builder.name,
                note: setState.builder.note,
                components: setState.builder.components,
            }),
        });
        const json = await res.json();
        if (!res.ok || !json.data) {
            throw new Error(json.error?.code || 'save_failed');
        }
        setState.builder.setId = json.data.id;
        setState.builder.components = mapComponentsWithRecipeNames(json.data.components || []);
        renderComponentsTable();
        renderComponentChecklist();
        await loadFreeContainers();
        switchStep(2);
    } catch (e) {
        showSetModalError('Set konnte nicht gespeichert werden.');
    }
}

function isStep1Valid() {
    const nameInput = document.querySelector('#set-form input[name="name"]');
    return !!(nameInput && nameInput.value.trim() && setState.builder.components.length);
}

function updateStep1NextState() {
    const btn = document.getElementById('step1-next');
    if (!btn) return;
    btn.disabled = !isStep1Valid();
}

function switchStep(step) {
    setState.builder.step = step;
    document.querySelectorAll('.wizard-step').forEach((el) => el.classList.add('hidden'));
    document.getElementById(`set-step-${step}`)?.classList.remove('hidden');
    document.querySelectorAll('.wizard-steps .pill').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.stepTarget === String(step));
    });
}

async function loadRecipesForSets() {
    try {
        const res = await fetch('/api/recipes?limit=200');
        const json = await res.json();
        setState.builder.recipes = json.data || [];
        const select = document.getElementById('component-recipe');
        if (select) {
            select.innerHTML = `<option value="">${t('sets.sources.choose_recipe')}</option>`;
            setState.builder.recipes.forEach((r) => {
                const opt = document.createElement('option');
                opt.value = r.id;
                opt.textContent = r.name;
                select.appendChild(opt);
            });
        }
    } catch (e) {
        // ignore
    }
}

async function loadItemTypeDefaults() {
    const select = document.getElementById('box-type');
    if (!select) return;

    const fallback = [
        { value: 'PROTEIN', label: t('types.protein') },
        { value: 'SIDE', label: t('types.side') },
        { value: 'SAUCE', label: t('types.sauce') },
        { value: 'BASE', label: t('types.base') },
        { value: 'BREAKFAST', label: t('types.breakfast') },
        { value: 'DESSERT', label: t('types.dessert') },
        { value: 'MISC', label: t('types.misc') },
    ];

    select.innerHTML = `<option value="">${t('sets.box.choose_type')}</option>`;

    try {
        const res = await fetch('/api/item-type-defaults');
        const json = await res.json();
        setState.builder.boxTypes = json.items || [];
        if (!setState.builder.boxTypes.length) {
            throw new Error('no_box_types');
        }

        setState.builder.boxTypes.forEach((it) => {
            if (!it.box_type) return;
            const opt = document.createElement('option');
            opt.value = it.box_type;
            opt.textContent = it.note || it.box_type;
            select.appendChild(opt);
        });
    } catch (e) {
        fallback.forEach((it) => {
            const opt = document.createElement('option');
            opt.value = it.value;
            opt.textContent = it.label;
            select.appendChild(opt);
        });
    }
}

async function loadFreeContainers() {
    try {
        const res = await fetch('/api/containers?free=1&active=1');
        const json = await res.json();
        setState.builder.containers = json.items || [];
        const select = document.getElementById('box-container');
        if (select) {
            select.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = t('sets.box.choose_container');
            select.appendChild(placeholder);
            (json.items || []).forEach((c) => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${c.container_code || t('sets.box.container_generic')}${c.note ? ' – ' + c.note : ''}`;
                select.appendChild(opt);
            });
        }
        const info = document.getElementById('free-container-count');
        if (info) info.textContent = t('sets.box.free_count', { count: (json.items || []).length });
    } catch (e) {
        // ignore
    }
}

function isBagContainerId(value) {
    if (value === null || value === undefined) return false;
    const normalized = String(value).toUpperCase();
    return ['FREEZER_BAG', 'VACUUM_BAG', '-1', '-2'].includes(normalized);
}

function renderComponentChecklist() {
    const container = document.getElementById('component-checklist');
    if (!container) return;
    container.innerHTML = '';
    setState.builder.components.forEach((comp) => {
        const label = document.createElement('label');
        label.className = 'checkbox';
        label.innerHTML = `<input type="checkbox" value="${comp.id || comp.tempId || ''}"> ${comp.component_type} – ${formatComponentSource(comp)}`;
        container.appendChild(label);
    });
}

function addBoxFromForm() {
    const form = document.getElementById('box-form');
    if (!form) return;
    const data = new FormData(form);
    const containerId = data.get('container_id');
    const boxType = data.get('box_type');
    const portionFactor = data.get('portion_factor');
    const portionText = data.get('portion_text');
    const componentIds = Array.from(form.querySelectorAll('#component-checklist input[type="checkbox"]:checked')).map((c) => parseInt(c.value, 10)).filter((v) => !Number.isNaN(v));

    if (!containerId || !boxType || !componentIds.length) {
        showSetModalError(t('sets.box.missing_fields'));
        return;
    }

    const isBag = isBagContainerId(containerId);
    const parsedContainerId = isBag ? null : parseInt(containerId, 10);

    if (!isBag && setState.builder.boxes.some((b) => String(b.container_id) === String(parsedContainerId))) {
        showSetModalError(t('sets.box.duplicate_container'));
        return;
    }

    if (!portionFactor && !portionText) {
        showSetModalError(t('sets.box.missing_portion'));
        return;
    }

    const selectedOption = form.querySelector('#box-container option:checked');
    const containerLabel = selectedOption ? selectedOption.textContent : String(containerId);

    setState.builder.boxes.push({
        container_id: isBag ? null : parsedContainerId,
        container_label: containerLabel,
        box_type: boxType,
        portion_factor: portionFactor || null,
        portion_text: portionText || null,
        component_ids: componentIds,
    });
    renderBoxesTable();
    form.reset();
    showSetModalError('');
}

function renderBoxesTable() {
    const body = document.getElementById('boxes-body');
    if (!body) return;
    body.innerHTML = '';
    if (!setState.builder.boxes.length) {
        body.innerHTML = `<tr class="empty-row"><td colspan="5" data-i18n="sets.box.empty">${t('sets.box.empty')}</td></tr>`;
        return;
    }

    setState.builder.boxes.forEach((box, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${box.box_type}</td>
            <td>${box.container_label || box.container_id || '-'}</td>
            <td>${box.portion_factor || '-'} / ${box.portion_text || '-'}</td>
            <td>${box.component_ids.length}</td>
            <td><button type="button" class="link" data-index="${idx}">${t('common.remove')}</button></td>
        `;
        tr.querySelector('button')?.addEventListener('click', () => {
            setState.builder.boxes.splice(idx, 1);
            renderBoxesTable();
        });
        body.appendChild(tr);
    });
}

async function packSet() {
    if (!setState.builder.setId) {
        showSetModalError(t('sets.box.save_first'));
        return;
    }
    if (!setState.builder.boxes.length) {
        showSetModalError(t('sets.box.add_one'));
        return;
    }

    try {
        const res = await fetch(`/api/sets/${setState.builder.setId}/boxes`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(setState.builder.boxes),
        });
        const json = await res.json();
        if (!res.ok || json.error) {
            throw new Error(json.error?.code || 'pack_failed');
        }
        closeSetModal();
        loadSets();
        alert(t('sets.box.packed'));
    } catch (e) {
        showSetModalError(t('sets.box.pack_failed'));
    }
}

function showSetModalError(message) {
    const el = document.getElementById('set-modal-error');
    if (!el) return;
    if (!message) {
        el.classList.add('hidden');
        el.textContent = '';
    } else {
        el.classList.remove('hidden');
        el.textContent = message;
    }
}

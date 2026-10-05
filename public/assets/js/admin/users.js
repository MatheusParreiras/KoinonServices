/**
 * User management list (Property Manager).
 *
 *  - Search box and filters, debounced (300 ms), call GET /admin/users/search.
 *    Responses that arrive out of order are ignored (only the latest request
 *    is rendered).
 *  - Rows are cloned from <template id="user-row"> and filled with textContent:
 *    names and e-mails are user input and are never parsed as HTML.
 *  - Inline deactivate / reactivate (with confirmation) and invitation resend,
 *    via the shared fetch helper (CSRF header added by http.js).
 *
 * The server re-checks everything: role, tenant, "not yourself", last manager.
 */
import { getJson, postJson, HttpError } from '../core/http.js';
import { messageFor } from '../core/async-form.js';
import { confirmAction } from './confirm.js';

const STATUS = {
    active: ['active', 'Ativo'],
    inactive: ['inactive', 'Desativado'],
    invited: ['invited', 'Convite pendente'],
    invitation_expired: ['expired', 'Convite expirado'],
};
const DEBOUNCE_MS = 300;

const filters = document.getElementById('user-filters');
const container = document.getElementById('user-list');
const rows = document.getElementById('user-rows');
const template = document.getElementById('user-row');
const pager = container.querySelector('[data-pager]');

let page = 1;
let pages = 1;
let requestSeq = 0;
let debounceTimer = null;

/** @param {'loading'|'empty'|'error'|'list'} name */
function showState(name) {
    container.querySelectorAll('[data-state]').forEach((element) => {
        element.hidden = element.dataset.state !== name;
    });
    container.setAttribute('aria-busy', String(name === 'loading'));
}

function currentQuery() {
    const params = new URLSearchParams();
    for (const [key, value] of new FormData(filters)) {
        const text = String(value).trim();
        if (text !== '') {
            params.set(key, text);
        }
    }
    params.set('page', String(page));
    return params;
}

/**
 * Applies a status to a row: badge text/class and which buttons are visible.
 * @param {HTMLTableRowElement} row
 * @param {string} status
 * @param {boolean} isSelf
 */
function applyStatus(row, status, isSelf) {
    const [variant, label] = STATUS[status] ?? ['inactive', status];
    const badge = row.querySelector('[data-field="status"]');
    badge.className = `pill pill--${variant}`;
    badge.textContent = label;

    const pending = status === 'invited' || status === 'invitation_expired';
    row.querySelector('[data-action="resend"]').hidden = !pending;
    row.querySelector('[data-action="deactivate"]').hidden = isSelf || status === 'inactive';
    row.querySelector('[data-action="reactivate"]').hidden = status !== 'inactive';
}

function renderRow(user) {
    const row = template.content.firstElementChild.cloneNode(true);
    const field = (name) => row.querySelector(`[data-field="${name}"]`);

    const link = field('name');
    link.textContent = user.full_name;
    link.href = `/admin/users/${encodeURIComponent(user.user_id)}/edit`;
    field('email').textContent = user.email;
    field('role').textContent = user.role_label;
    field('unit').textContent = user.unit_label ?? '—';
    field('self').hidden = !user.is_self;

    row.dataset.userId = String(user.user_id);
    row.dataset.name = user.full_name;
    row.dataset.self = user.is_self ? '1' : '0';
    applyStatus(row, user.status, user.is_self);
    return row;
}

async function load() {
    const seq = ++requestSeq;
    showState('loading');
    try {
        const { data } = await getJson(`/admin/users/search?${currentQuery()}`);
        if (seq !== requestSeq) {
            return; // a newer search started meanwhile
        }
        rows.replaceChildren(...data.users.map(renderRow));
        ({ page, pages } = data.pagination);
        pager.hidden = data.pagination.total === 0;
        pager.querySelector('[data-pager-info]').textContent =
            `${data.pagination.total} usuário(s) · página ${page} de ${pages}`;
        pager.querySelector('[data-page="prev"]').disabled = page <= 1;
        pager.querySelector('[data-page="next"]').disabled = page >= pages;
        showState(data.users.length > 0 ? 'list' : 'empty');
    } catch (error) {
        if (seq === requestSeq) {
            console.error('Failed to load users', error);
            showState('error');
        }
    }
}

function scheduleLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        page = 1;
        load();
    }, DEBOUNCE_MS);
}

const ACTIONS = {
    deactivate: {
        confirm: (name) => `Desativar ${name}? A pessoa perderá o acesso a este condomínio na próxima ação.`,
        url: (id) => `/admin/users/${id}/deactivate`,
    },
    reactivate: {
        confirm: (name) => `Reativar ${name}?`,
        url: (id) => `/admin/users/${id}/reactivate`,
    },
    resend: {
        confirm: null,
        url: (id) => `/admin/users/${id}/invitation/resend`,
    },
};

rows.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button || button.disabled) {
        return;
    }
    const row = button.closest('[data-row]');
    const action = ACTIONS[button.dataset.action];
    const feedback = row.querySelector('[data-feedback]');
    const id = encodeURIComponent(row.dataset.userId);

    if (action.confirm && !(await confirmAction(action.confirm(row.dataset.name)))) {
        return;
    }

    button.disabled = true;
    feedback.dataset.kind = 'info';
    feedback.textContent = 'Enviando…';
    try {
        const payload = await postJson(action.url(id), {});
        feedback.dataset.kind = 'success';
        feedback.textContent = payload?.message ?? 'Concluído.';
        if (payload?.user?.status) {
            applyStatus(row, payload.user.status, row.dataset.self === '1');
        }
    } catch (error) {
        feedback.dataset.kind = 'error';
        feedback.textContent = messageFor(error);
        if (!(error instanceof HttpError)) {
            console.error(error);
        }
    } finally {
        button.disabled = false;
    }
});

filters.addEventListener('input', scheduleLoad);
filters.addEventListener('submit', (event) => {
    event.preventDefault();
    scheduleLoad();
});
pager.addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) {
        return;
    }
    page = Math.min(pages, Math.max(1, page + (button.dataset.page === 'next' ? 1 : -1)));
    load();
});
container.querySelector('[data-retry]').addEventListener('click', load);

load();

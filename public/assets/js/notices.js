/**
 * Notice Board: loads notices from GET /api/notices and, for authorised roles,
 * publishes new ones through POST /api/notices.
 *
 * SECURITY: notice text is user content. It is only ever written with
 * textContent (never innerHTML), so markup in a notice is shown as plain text
 * and cannot run as script.
 */
import { getJson, postJson, HttpError } from './core/http.js';

const PRIORITY_LABELS = {
    normal: 'Normal',
    important: 'Importante',
    urgent: 'Urgente',
};

const dateFormatter = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' });

const board = document.getElementById('notice-board');
const list = document.getElementById('notice-list');
const template = document.getElementById('notice-template');

/**
 * Shows exactly one of: loading | empty | error | list.
 * @param {'loading'|'empty'|'error'|'list'} name
 */
function showState(name) {
    board.querySelectorAll('[data-state]').forEach((element) => {
        element.hidden = element.dataset.state !== name;
    });
    list.hidden = name !== 'list';
    board.setAttribute('aria-busy', String(name === 'loading'));
}

/**
 * Builds one <li> from the template.
 * @param {{id:number,title:string,body:string,priority:string,is_pinned:boolean,published_at:string,author_name:string|null}} notice
 */
function renderNotice(notice) {
    const item = template.content.firstElementChild.cloneNode(true);
    const field = (name) => item.querySelector(`[data-field="${name}"]`);

    field('title').textContent = notice.title;
    field('body').textContent = notice.body;
    field('author').textContent = notice.author_name ?? 'Administração';

    const badge = field('priority');
    // Only known values become CSS classes; anything else falls back to "normal".
    const priority = Object.hasOwn(PRIORITY_LABELS, notice.priority) ? notice.priority : 'normal';
    badge.textContent = PRIORITY_LABELS[priority];
    badge.classList.add(`badge--${priority}`);

    if (notice.is_pinned) {
        field('pinned').hidden = false;
        item.classList.add('notice--pinned');
    }

    const time = field('date');
    if (notice.published_at) {
        time.dateTime = notice.published_at;
        time.textContent = dateFormatter.format(new Date(notice.published_at));
    }

    return item;
}

async function loadNotices() {
    showState('loading');
    try {
        const { data } = await getJson('/api/notices');
        list.replaceChildren(...data.map(renderNotice));
        showState(data.length > 0 ? 'list' : 'empty');
    } catch (error) {
        console.error('Failed to load notices', error);
        showState('error');
    }
}

/** Wires the "Novo aviso" dialog. Absent from the page for roles without permission. */
function setupNoticeForm() {
    const openButton = document.getElementById('open-notice-form');
    const dialog = document.getElementById('notice-dialog');
    if (!openButton || !dialog) {
        return;
    }

    const form = document.getElementById('notice-form');
    const generalError = document.getElementById('notice-form-error');
    const submitButton = form.querySelector('button[type="submit"]');

    const clearErrors = () => {
        generalError.hidden = true;
        generalError.textContent = '';
        form.querySelectorAll('[data-error-for]').forEach((element) => {
            element.textContent = '';
        });
    };

    openButton.addEventListener('click', () => {
        form.reset();
        clearErrors();
        dialog.showModal();
    });

    form.querySelector('[data-close]').addEventListener('click', () => dialog.close());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearErrors();
        submitButton.disabled = true;

        const formData = new FormData(form);
        try {
            await postJson('/api/notices', {
                title: String(formData.get('title') ?? ''),
                body: String(formData.get('body') ?? ''),
                priority: String(formData.get('priority') ?? 'normal'),
                is_pinned: formData.get('is_pinned') === '1',
                expires_at: String(formData.get('expires_at') ?? ''),
            });
            dialog.close();
            await loadNotices();
        } catch (error) {
            if (error instanceof HttpError && error.status === 422 && error.payload?.errors) {
                for (const [name, message] of Object.entries(error.payload.errors)) {
                    const target = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
                    if (target) {
                        target.textContent = message;
                    }
                }
            } else {
                const message = error instanceof HttpError ? error.payload?.error : null;
                generalError.textContent = message ?? 'Não foi possível publicar o aviso. Tente novamente.';
                generalError.hidden = false;
            }
        } finally {
            submitButton.disabled = false;
        }
    });
}

document.getElementById('retry-notices').addEventListener('click', loadNotices);
setupNoticeForm();
loadNotices();

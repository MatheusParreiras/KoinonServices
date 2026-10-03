/**
 * Moderation view: delete a reported post or dismiss its reports, without
 * reloading. The page is server-rendered; this script only sends the actions.
 * The server re-checks the moderator role and the tenant on every request.
 */
import * as api from './api.js';
import { fillAvatar } from './render.js';
import { showToast } from './toast.js';

const listElement = document.getElementById('moderation-list');
const counter = document.querySelector('[data-queue-count]');

const ACTIONS = {
    delete: {
        confirm: 'Excluir esta publicação? Curtidas, comentários e denúncias também serão apagados. Esta ação não pode ser desfeita.',
        run: (id) => api.deletePostAsModerator(id),
        done: 'Publicação excluída.',
    },
    dismiss: {
        confirm: 'Descartar as denúncias? A publicação volta (ou continua) visível no mural.',
        run: (id) => api.dismissReports(id),
        done: 'Denúncias descartadas.',
    },
};

function removeCard(card) {
    card.classList.add('is-leaving');
    card.addEventListener('transitionend', () => card.remove(), { once: true });
    setTimeout(() => card.remove(), 400);
    const remaining = listElement.querySelectorAll('[data-post-id]:not(.is-leaving)').length;
    counter.textContent = `${remaining} pendente(s)`;
}

listElement?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const action = button ? ACTIONS[button.dataset.action] : null;
    if (!action || button.disabled) {
        return;
    }
    const card = button.closest('[data-post-id]');
    if (!window.confirm(action.confirm)) {
        return;
    }

    const buttons = card.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    try {
        await action.run(card.dataset.postId);
        showToast(action.done, 'success');
        removeCard(card);
    } catch (error) {
        showToast(api.errorMessage(error), 'error');
        if (error instanceof api.HttpError && error.status === 404) {
            removeCard(card); // already handled by another moderator
            return;
        }
        buttons.forEach((b) => { b.disabled = false; });
    }
});

document.querySelectorAll('[data-initials-of]').forEach((el) => fillAvatar(el, el.dataset.initialsOf));

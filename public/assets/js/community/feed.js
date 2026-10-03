/**
 * Community feed: event handlers and state.
 *
 * One delegated listener per event type on the #feed container handles every
 * card, including cards added later by "Load more" or by publishing, so no
 * per-card listeners need to be attached or cleaned up.
 */
import * as api from './api.js';
import { renderPost, renderComment, setLikeState, setCommentsCount, setReported, fillAvatar } from './render.js';
import { showToast } from './toast.js';

const feed = document.getElementById('feed');
const list = feed.querySelector('[data-feed-list]');
const template = document.getElementById('post-card-template');
const loadMoreButton = document.getElementById('load-more');
const composer = document.getElementById('post-composer');
const filter = document.getElementById('category-filter');
const reportDialog = document.getElementById('report-dialog');
const reportForm = document.getElementById('report-form');

/** Paging state. anchor pins the feed to the posts that existed on first load. */
const state = { category: '', offset: 0, anchor: 0, hasMore: false, loading: false };

// ---------------------------------------------------------------- helpers

function showState(name) {
    feed.querySelectorAll('[data-state]').forEach((el) => {
        el.hidden = el.dataset.state !== name;
    });
    feed.setAttribute('aria-busy', String(name === 'loading'));
}

const cardOf = (element) => element.closest('[data-post-id]');

/** Runs fn with the button disabled, so a double click cannot send twice. */
async function withBusy(button, fn) {
    if (button.disabled) {
        return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        await fn();
    } finally {
        button.removeAttribute('aria-busy');
        if (button.isConnected && !button.classList.contains('is-done')) {
            button.disabled = false;
        }
    }
}

// ---------------------------------------------------------------- loading pages

async function loadPage({ reset = false } = {}) {
    if (state.loading) {
        return;
    }
    state.loading = true;
    if (reset) {
        state.offset = 0;
        state.anchor = 0;
        list.replaceChildren();
        showState('loading');
    }
    loadMoreButton.disabled = true;

    try {
        const data = await api.listPosts({ category: state.category, offset: state.offset, anchor: state.anchor });
        // Skip cards already on screen (e.g. a post the user just published).
        const fresh = data.posts.filter((post) => !list.querySelector(`[data-post-id="${post.id}"]`));
        list.append(...fresh.map((post) => renderPost(template, post)));

        state.offset = data.next_offset;
        state.anchor = data.anchor;
        state.hasMore = data.has_more;
        showState(list.children.length === 0 ? 'empty' : null);
    } catch (error) {
        if (reset) {
            showState('error');
        } else {
            showToast(api.errorMessage(error), 'error');
        }
    } finally {
        state.loading = false;
        loadMoreButton.disabled = false;
        loadMoreButton.hidden = !state.hasMore;
    }
}

// ---------------------------------------------------------------- likes (optimistic)

async function toggleLike(button) {
    if (button.disabled) {
        return; // a request is in flight: do not flip the UI without sending one
    }
    const card = cardOf(button);
    const wasLiked = card.dataset.liked === 'true';
    const previousCount = Number(card.dataset.likesCount);
    const wantLiked = !wasLiked;

    // 1. Optimistic: the UI changes at once.
    setLikeState(card, wantLiked, previousCount + (wantLiked ? 1 : -1));

    await withBusy(button, async () => {
        try {
            // 2. Reconcile with the authoritative state and count from the server.
            const result = await api.setLike(card.dataset.postId, wantLiked);
            setLikeState(card, result.liked, result.likes_count);
        } catch (error) {
            // 3. Roll back on any failure.
            setLikeState(card, wasLiked, previousCount);
            showToast(api.errorMessage(error), 'error');
            if (error instanceof api.HttpError && error.status === 404) {
                card.remove(); // removed by a moderator meanwhile
            }
        }
    });
}

// ---------------------------------------------------------------- comments

async function submitComment(form) {
    const card = cardOf(form);
    const input = form.elements.namedItem('body');
    const button = form.querySelector('[type="submit"]');
    const body = input.value.trim();

    if (body === '') {
        showToast('Escreva algo antes de enviar.', 'info');
        input.focus();
        return;
    }

    await withBusy(button, async () => {
        input.disabled = true;
        try {
            const result = await api.addComment(card.dataset.postId, body);
            card.querySelector('[data-field="comments"]').append(renderComment(result.comment));
            setCommentsCount(card, result.comments_count);
            input.value = '';
        } catch (error) {
            showToast(api.errorMessage(error), 'error'); // the text stays in the input for a retry
        } finally {
            input.disabled = false;
            input.focus();
        }
    });
}

async function loadAllComments(button) {
    const card = cardOf(button);
    await withBusy(button, async () => {
        try {
            const result = await api.listComments(card.dataset.postId);
            card.querySelector('[data-field="comments"]').replaceChildren(...result.comments.map(renderComment));
            setCommentsCount(card, Number(card.dataset.commentsCount));
        } catch (error) {
            showToast(api.errorMessage(error), 'error');
        }
    });
}

// ---------------------------------------------------------------- reports

function openReportDialog(button) {
    reportForm.reset();
    reportDialog.dataset.postId = cardOf(button).dataset.postId;
    reportDialog.showModal();
}

async function submitReport(event) {
    event.preventDefault();
    const reason = reportForm.elements.namedItem('reason').value;
    if (!reason) {
        showToast('Escolha um motivo para a denúncia.', 'info');
        return;
    }
    const details = reportForm.elements.namedItem('details').value.trim();
    const postId = reportDialog.dataset.postId;
    const card = list.querySelector(`[data-post-id="${CSS.escape(postId)}"]`);
    const sendButton = reportForm.querySelector('[type="submit"]');

    await withBusy(sendButton, async () => {
        try {
            await api.reportPost(postId, reason, details);
            showToast('Denúncia enviada. Obrigado por ajudar a manter a comunidade saudável!', 'success');
            if (card) setReported(card);
            reportDialog.close();
        } catch (error) {
            if (error instanceof api.HttpError && error.status === 409) {
                showToast('Você já denunciou esta publicação. A administração vai analisar.', 'info');
                if (card) setReported(card);
                reportDialog.close();
            } else {
                showToast(api.errorMessage(error), 'error');
            }
        }
    });
}

// ---------------------------------------------------------------- composer

const counter = composer.querySelector('[data-char-counter]');
const updateCounter = () => {
    const length = composer.elements.namedItem('body').value.length;
    counter.textContent = `${length} / ${counter.dataset.max}`;
    counter.classList.toggle('is-near-limit', length > Number(counter.dataset.max) * 0.9);
};

async function submitPost(event) {
    event.preventDefault();
    const textarea = composer.elements.namedItem('body');
    const select = composer.elements.namedItem('category');
    const body = textarea.value.trim();

    if (body === '') {
        showToast('Escreva algo antes de publicar.', 'info');
        textarea.focus();
        return;
    }
    if (select.value === '') {
        showToast('Escolha uma categoria.', 'info');
        select.focus();
        return;
    }

    await withBusy(composer.querySelector('[type="submit"]'), async () => {
        try {
            const { post } = await api.createPost(select.value, body);
            composer.reset();
            updateCounter();
            if (state.category === '' || state.category === post.category.code) {
                list.prepend(renderPost(template, post));
                showState(null);
                showToast('Publicado!', 'success');
            } else {
                showToast(`Publicado em “${post.category.name}”.`, 'success');
            }
        } catch (error) {
            showToast(api.errorMessage(error), 'error');
        }
    });
}

// ---------------------------------------------------------------- wiring (event delegation)

feed.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if (!button || !feed.contains(button)) {
        return;
    }
    switch (button.dataset.action) {
        case 'like':
            toggleLike(button);
            break;
        case 'focus-comment':
            cardOf(button).querySelector('[data-comment-form] input[name="body"]').focus();
            break;
        case 'report':
            openReportDialog(button);
            break;
        case 'load-comments':
            loadAllComments(button);
            break;
        case 'retry':
            loadPage({ reset: true });
            break;
        default:
            break;
    }
});

feed.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-comment-form]');
    if (form) {
        event.preventDefault();
        submitComment(form);
    }
});

filter.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-category]');
    if (!chip || state.loading) {
        return;
    }
    filter.querySelectorAll('[data-category]').forEach((c) => {
        c.classList.toggle('is-active', c === chip);
        c.setAttribute('aria-pressed', String(c === chip));
    });
    state.category = chip.dataset.category;
    loadPage({ reset: true });
});

composer.addEventListener('submit', submitPost);
composer.elements.namedItem('body').addEventListener('input', updateCounter);
loadMoreButton.addEventListener('click', () => loadPage());
reportForm.addEventListener('submit', submitReport);
reportForm.querySelector('[data-close-dialog]').addEventListener('click', () => reportDialog.close());

document.querySelectorAll('[data-initials-of]').forEach((el) => fillAvatar(el, el.dataset.initialsOf));

loadPage({ reset: true });

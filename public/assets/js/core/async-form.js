/**
 * Reusable "submit a form without reloading the page".
 *
 * Markup contract:
 *   <form method="post" action="/api/..." data-async="name">
 *       <input name="...">                      fields are sent as a JSON object
 *       <button type="submit">...</button>      disabled while the request is in flight
 *       <span data-feedback role="status"></span>   receives user-friendly messages
 *   </form>
 *
 * Behaviour:
 *   - Sends the CSRF token in the X-CSRF-Token header (via http.js).
 *   - Ignores double submits while a request is running.
 *   - Optional client-side validate() for instant feedback; the server always re-validates.
 *   - Maps network errors and non-2xx JSON answers to readable messages, and marks the
 *     fields named in the server's {"errors": {field: message}} as invalid.
 *   - Everything is written with textContent: server data is never parsed as HTML.
 */
import { postJson, HttpError } from './http.js';

const FALLBACK_MESSAGES = {
    0: 'Sem conexão com o servidor. Tente novamente.',
    403: 'Você não tem permissão para esta ação.',
    404: 'Registro não encontrado. Recarregue a página.',
    409: 'Esta ação já foi feita por outra pessoa. Recarregue a página.',
    419: 'Sua sessão expirou. Recarregue a página e tente novamente.',
    422: 'Verifique os dados informados.',
};
const GENERIC_MESSAGE = 'Não foi possível concluir a ação. Tente novamente em instantes.';

/**
 * Turns any error into a message safe to show to the user.
 * Server messages are used when present: the API only returns user-facing text.
 * @param {unknown} error
 * @returns {string}
 */
export function messageFor(error) {
    if (error instanceof HttpError) {
        const serverMessage = typeof error.payload?.error === 'string' ? error.payload.error : null;
        return serverMessage ?? FALLBACK_MESSAGES[error.status] ?? GENERIC_MESSAGE;
    }
    return GENERIC_MESSAGE;
}

/**
 * @param {HTMLElement|null} element
 * @param {string} text
 * @param {'info'|'success'|'error'} kind
 */
function showFeedback(element, text, kind) {
    if (!element) {
        return;
    }
    element.textContent = text;
    element.dataset.kind = kind;
}

/** Marks fields named by the server as invalid (aria-invalid drives the CSS). */
function markInvalidFields(form, errors) {
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
    if (!errors || typeof errors !== 'object') {
        return;
    }
    for (const name of Object.keys(errors)) {
        const field = form.elements.namedItem(name);
        if (field instanceof HTMLElement) {
            field.setAttribute('aria-invalid', 'true');
        }
    }
}

/**
 * Wires one form.
 *
 * @param {HTMLFormElement} form
 * @param {{
 *   validate?: (data: Record<string, string>, form: HTMLFormElement) => (string|null),
 *   onSuccess?: (payload: any, form: HTMLFormElement) => void,
 * }} [options]
 */
export function bindAsyncForm(form, { validate, onSuccess } = {}) {
    const feedback = form.querySelector('[data-feedback]');
    const submitButton = form.querySelector('[type="submit"]');
    let inFlight = false;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (inFlight) {
            return; // a double click must not send the action twice
        }

        const data = Object.fromEntries(new FormData(form));
        delete data._csrf; // the token travels in the header

        const clientError = validate ? validate(data, form) : null;
        if (clientError) {
            showFeedback(feedback, clientError, 'error');
            return;
        }

        inFlight = true;
        if (submitButton) {
            submitButton.disabled = true;
        }
        form.setAttribute('aria-busy', 'true');
        showFeedback(feedback, 'Enviando…', 'info');

        try {
            const payload = await postJson(form.getAttribute('action'), data);
            markInvalidFields(form, null);
            showFeedback(feedback, typeof payload?.message === 'string' ? payload.message : 'Concluído.', 'success');
            onSuccess?.(payload, form);
        } catch (error) {
            showFeedback(feedback, messageFor(error), 'error');
            markInvalidFields(form, error instanceof HttpError ? error.payload?.errors : null);
            if (!(error instanceof HttpError)) {
                console.error(error); // programming error, not a server answer
            }
        } finally {
            inFlight = false;
            form.removeAttribute('aria-busy');
            if (submitButton && form.isConnected) {
                submitButton.disabled = false;
            }
        }
    });
}

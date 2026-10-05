/**
 * Confirmation dialog for destructive or high-impact actions.
 *
 *   <form method="post" action="..." data-confirm="Excluir este aviso?"> ... </form>
 *
 * Any form with data-confirm is intercepted: a native <dialog> asks first and
 * the form is submitted only after "Confirmar". The message comes from the
 * (server-escaped) attribute and is inserted with textContent, never innerHTML.
 * The server enforces every rule anyway; this only prevents accidental clicks.
 *
 * confirmAction() is exported for fetch-based actions (users.js).
 */

let dialog = null;

function buildDialog() {
    const element = document.createElement('dialog');
    element.className = 'dialog dialog--confirm';
    element.setAttribute('aria-labelledby', 'confirm-dialog-title');

    const form = document.createElement('form');
    form.method = 'dialog';
    form.className = 'form';

    const title = document.createElement('h2');
    title.className = 'dialog__title';
    title.id = 'confirm-dialog-title';
    title.textContent = 'Confirmar ação';

    const message = document.createElement('p');
    message.dataset.message = '';

    const actions = document.createElement('div');
    actions.className = 'dialog__actions';
    const cancel = document.createElement('button');
    cancel.type = 'submit';
    cancel.value = 'cancel';
    cancel.className = 'btn';
    cancel.textContent = 'Cancelar';
    const confirm = document.createElement('button');
    confirm.type = 'submit';
    confirm.value = 'confirm';
    confirm.className = 'btn btn--danger';
    confirm.textContent = 'Confirmar';
    actions.append(cancel, confirm);

    form.append(title, message, actions);
    element.append(form);
    document.body.append(element);

    return element;
}

/**
 * Asks the user to confirm. Resolves true only for "Confirmar"
 * (Esc, "Cancelar" and closing the dialog all resolve false).
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export function confirmAction(text) {
    dialog ??= buildDialog();
    dialog.querySelector('[data-message]').textContent = text;
    dialog.returnValue = '';

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
        dialog.showModal();
        dialog.querySelector('button[value="cancel"]').focus(); // safe default
    });
}

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm || form.dataset.confirmed === '1') {
        return;
    }
    event.preventDefault();
    if (await confirmAction(form.dataset.confirm)) {
        form.dataset.confirmed = '1';
        form.querySelector('[type="submit"]')?.setAttribute('disabled', ''); // no double submit
        form.submit();
    }
});

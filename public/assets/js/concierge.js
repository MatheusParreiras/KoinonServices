/**
 * Concierge desk: "Registrar saída" and "Confirmar retirada" without reloading.
 * Uses the reusable bindAsyncForm() and updates the row in place on success.
 */
import { bindAsyncForm } from './core/async-form.js';

/** Decreases a header counter such as "Visitantes no condomínio (3)". */
function decrementCounter(name) {
    const counter = document.querySelector(`[data-counter="${name}"]`);
    if (counter) {
        counter.textContent = String(Math.max(0, Number(counter.textContent) - 1));
    }
}

/**
 * Replaces the action cell of a row with a confirmation text.
 * textContent only: the name comes from user input (server echo).
 */
function markRowDone(form, text) {
    const cell = form.closest('[data-status-cell]');
    const row = form.closest('[data-row]');
    const label = document.createElement('span');
    label.className = 'done-label';
    label.setAttribute('role', 'status');
    label.textContent = text;
    cell?.replaceChildren(label);
    row?.classList.add('row--done');
}

document.querySelectorAll('form[data-async="visit-exit"]').forEach((form) => {
    bindAsyncForm(form, {
        onSuccess: (payload, f) => {
            markRowDone(f, `Saída às ${payload.visit.exit_time}`);
            decrementCounter(f.dataset.decrement);
        },
    });
});

document.querySelectorAll('form[data-async="package-pickup"]').forEach((form) => {
    bindAsyncForm(form, {
        // Instant feedback; the server repeats every check (and verifies the code).
        validate: (data) => {
            if (!/^\d{6}$/.test(String(data.pickup_code ?? '').trim())) {
                return 'Informe o código de 6 dígitos.';
            }
            if (String(data.picked_up_by_name ?? '').trim().length < 3) {
                return 'Informe o nome de quem está retirando.';
            }
            return null;
        },
        onSuccess: (payload, f) => {
            markRowDone(f, `Retirada por ${payload.package.picked_up_by_name} em ${payload.package.picked_up_at}`);
            decrementCounter(f.dataset.decrement);
        },
    });
});

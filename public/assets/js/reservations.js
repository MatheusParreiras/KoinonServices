/**
 * Reservations page.
 *
 * Client-side validation of the booking form gives instant feedback (past date,
 * end before start, booking window, capacity). It COMPLEMENTS the server: every
 * rule is checked again in ReservationController/ReservationService, and the
 * overlap check can only be done on the server, under a database lock.
 */

const MIN_MINUTES = 30;

const form = document.getElementById('booking-form');

/** Adds days to a "YYYY-MM-DD" string (local calendar, no time-zone drift). */
function addDays(isoDate, days) {
    const [y, m, d] = isoDate.split('-').map(Number);
    const date = new Date(y, m - 1, d + days);
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const toMinutes = (hhmm) => {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
};

/** Shows (or clears) the client-side error next to a field. */
function setFieldError(field, message) {
    const container = field.closest('.form__field');
    let slot = container?.querySelector('[data-client-error]');
    if (!slot && container) {
        slot = document.createElement('span');
        slot.className = 'form__error';
        slot.dataset.clientError = '';
        container.append(slot);
    }
    if (slot) {
        slot.textContent = message ?? '';
    }
    if (message) {
        field.setAttribute('aria-invalid', 'true');
    } else {
        field.removeAttribute('aria-invalid');
    }
}

function selectedArea() {
    const option = form.elements.common_area_id.selectedOptions[0];
    return option && option.value !== '' ? option.dataset : null;
}

/** @returns {Map<HTMLElement, string>} field => message */
function validateBooking() {
    const f = form.elements;
    const errors = new Map();
    const today = form.dataset.today; // condominium's local date, from the server
    const area = selectedArea();

    if (!area) {
        errors.set(f.common_area_id, 'Selecione a área.');
    }
    if (f.unit_id.value === '') {
        errors.set(f.unit_id, 'Selecione a unidade.');
    }

    const date = f.reservation_date.value;
    if (date === '') {
        errors.set(f.reservation_date, 'Informe a data.');
    } else if (date < today) {
        errors.set(f.reservation_date, 'A data não pode estar no passado.');
    } else if (area && date > addDays(today, Number(area.maxDays))) {
        errors.set(f.reservation_date, `Reservas com até ${area.maxDays} dias de antecedência.`);
    }

    const start = f.start_time.value;
    const end = f.end_time.value;
    if (start === '') {
        errors.set(f.start_time, 'Informe o início.');
    }
    if (end === '') {
        errors.set(f.end_time, 'Informe o término.');
    }
    if (start !== '' && end !== '') {
        if (toMinutes(end) <= toMinutes(start)) {
            errors.set(f.end_time, 'O término deve ser depois do início.');
        } else if (toMinutes(end) - toMinutes(start) < MIN_MINUTES) {
            errors.set(f.end_time, `A reserva deve durar pelo menos ${MIN_MINUTES} minutos.`);
        }
    }

    const guests = Number(f.guest_count.value || 0);
    if (!Number.isInteger(guests) || guests < 0) {
        errors.set(f.guest_count, 'Número de convidados inválido.');
    } else if (area && area.maxPeople !== '' && guests > Number(area.maxPeople)) {
        errors.set(f.guest_count, `Capacidade máxima: ${area.maxPeople} pessoas.`);
    }

    return errors;
}

function updateAreaHint() {
    const hint = form.querySelector('[data-area-hint]');
    const area = selectedArea();
    if (!hint) {
        return;
    }
    if (!area) {
        hint.textContent = '';
        return;
    }
    const parts = [area.approval === '1' ? 'Requer aprovação da administração' : 'Confirmação imediata'];
    if (area.maxPeople !== '') {
        parts.push(`até ${area.maxPeople} pessoas`);
    }
    parts.push(`antecedência mínima de ${area.minHours}h`);
    hint.textContent = parts.join(' · ');
}

if (form) {
    form.addEventListener('submit', (event) => {
        const errors = validateBooking();
        for (const field of form.querySelectorAll('input, select, textarea')) {
            setFieldError(field, errors.get(field) ?? null);
        }
        if (errors.size > 0) {
            event.preventDefault(); // stop here; nothing invalid reaches the server from this form
            errors.keys().next().value.focus();
            return;
        }
        form.querySelector('[type="submit"]').disabled = true; // prevent double booking requests
    });

    // Re-validate a field as soon as the user fixes it.
    form.addEventListener('change', (event) => {
        if (event.target === form.elements.common_area_id) {
            updateAreaHint();
        }
        if (event.target.getAttribute('aria-invalid') === 'true') {
            const errors = validateBooking();
            setFieldError(event.target, errors.get(event.target) ?? null);
        }
    });

    updateAreaHint();
}

// Confirmation for destructive buttons (cancel reservation).
document.querySelectorAll('form[data-confirm]').forEach((confirmForm) => {
    confirmForm.addEventListener('submit', (event) => {
        if (!window.confirm(confirmForm.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

/**
 * Period report form (Property Manager).
 *
 *  - Picking a month fills "De"/"Até" with its first and last day.
 *  - Submitting fetches GET /admin/finance/reports/period?from=&to= and writes
 *    the totals with textContent (amounts arrive as DECIMAL strings, e.g.
 *    "1234.50", and are only formatted here, as strings, never added up).
 *  - The "Exportar CSV" link always points at the same period: it is a plain
 *    GET download, so the browser saves the file the server builds.
 */
import { getJson } from '../core/http.js';
import { messageFor } from '../core/async-form.js';

const form = document.getElementById('period-form');
const totals = document.getElementById('period-totals');
const feedback = document.getElementById('period-feedback');
const csvLink = document.getElementById('period-csv');

/**
 * "1234567.5" → "R$ 1.234.567,50", with string operations only (the same
 * rule as money_br() in PHP): money is never turned into a float.
 * @param {string} decimal
 */
function formatMoney(decimal) {
    const text = String(decimal);
    const negative = text.startsWith('-');
    const [whole, fraction = ''] = text.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${negative ? '-' : ''}R$ ${grouped},${fraction.padEnd(2, '0').slice(0, 2)}`;
}

const isDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value);

function period() {
    return {
        from: String(form.elements.namedItem('from').value),
        to: String(form.elements.namedItem('to').value),
    };
}

function updateCsvLink() {
    const { from, to } = period();
    csvLink.href = `/admin/finance/reports/period.csv?${new URLSearchParams({ from, to })}`;
}

function setTotal(name, text) {
    const element = totals.querySelector(`[data-total="${name}"]`);
    if (element) {
        element.textContent = text;
    }
}

async function loadReport() {
    const { from, to } = period();
    if (!isDate(from) || !isDate(to) || to < from) {
        feedback.dataset.kind = 'error';
        feedback.textContent = 'Informe um período válido (a data final não pode ser anterior à inicial).';
        return;
    }

    totals.setAttribute('aria-busy', 'true');
    feedback.dataset.kind = 'info';
    feedback.textContent = 'Calculando…';
    try {
        const { data } = await getJson(`${form.getAttribute('action')}?${new URLSearchParams({ from, to })}`);
        setTotal('billed', formatMoney(data.billed));
        setTotal('received', formatMoney(data.received));
        setTotal('pending', formatMoney(data.pending));
        setTotal('overdue', formatMoney(data.overdue));
        setTotal('invoice_count', `${data.invoice_count} cobrança(s)`);
        setTotal('payment_count', `${data.payment_count} pagamento(s)`);
        setTotal('overdue_count', `${data.overdue_count} cobrança(s) vencida(s)`);
        feedback.textContent = '';
    } catch (error) {
        feedback.dataset.kind = 'error';
        feedback.textContent = messageFor(error);
    } finally {
        totals.setAttribute('aria-busy', 'false');
    }
}

form.elements.namedItem('month').addEventListener('change', (event) => {
    const value = String(event.target.value); // "YYYY-MM"
    if (!/^\d{4}-\d{2}$/.test(value)) {
        return;
    }
    const [year, month] = value.split('-').map(Number);
    const lastDay = new Date(year, month, 0).getDate(); // day 0 of next month
    form.elements.namedItem('from').value = `${value}-01`;
    form.elements.namedItem('to').value = `${value}-${String(lastDay).padStart(2, '0')}`;
    updateCsvLink();
    loadReport();
});

form.addEventListener('input', updateCsvLink);
form.addEventListener('submit', (event) => {
    event.preventDefault();
    updateCsvLink();
    loadReport();
});

updateCsvLink();
loadReport();

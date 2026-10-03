/**
 * Small toast notifications. Text only (textContent), so a message that echoes
 * user input can never inject markup.
 */
const DURATION_MS = 4000;

/**
 * @param {string} message
 * @param {'success'|'error'|'info'} [kind]
 */
export function showToast(message, kind = 'info') {
    const region = document.getElementById('toast-region');
    if (!region) {
        return;
    }

    const toast = document.createElement('div');
    toast.className = `c-toast c-toast--${kind}`;
    toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    region.append(toast);

    // Next frame: start the CSS enter transition.
    requestAnimationFrame(() => toast.classList.add('is-visible'));

    setTimeout(() => {
        toast.classList.remove('is-visible');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
        setTimeout(() => toast.remove(), 500); // fallback if transitions are disabled
    }, DURATION_MS);
}

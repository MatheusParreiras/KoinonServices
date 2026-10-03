/**
 * Small fetch() wrapper shared by every page script.
 *
 * - Sends cookies (same-origin) and asks for JSON.
 * - Adds the CSRF token (from <meta name="csrf-token">) to state-changing requests.
 * - Redirects to the login page when the session has expired (401).
 * - Always rejects with HttpError, including network failures and timeouts
 *   (status 0), so callers need only one error path.
 */

const TIMEOUT_MS = 15000;

export class HttpError extends Error {
    /**
     * @param {number} status HTTP status, or 0 for network failure / timeout.
     * @param {any} payload Decoded JSON body ({error, errors}), or null.
     */
    constructor(status, payload) {
        super(`HTTP ${status}`);
        this.status = status;
        this.payload = payload;
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function request(method, url, body) {
    const headers = { Accept: 'application/json' };
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
    const init = { method, headers, credentials: 'same-origin', signal: controller.signal };

    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken();
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch (cause) {
        // fetch() only rejects when no HTTP answer arrived: offline, DNS, CORS, abort.
        const timedOut = cause?.name === 'AbortError';
        throw new HttpError(0, {
            error: timedOut
                ? 'O servidor demorou para responder. Tente novamente.'
                : 'Sem conexão com o servidor. Verifique sua internet e tente novamente.',
        });
    } finally {
        clearTimeout(timer);
    }

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null; // empty or non-JSON body (e.g. a proxy error page)
    }

    if (response.status === 401) {
        window.location.assign('/login');
    }
    if (!response.ok) {
        throw new HttpError(response.status, payload);
    }

    return payload;
}

export const getJson = (url) => request('GET', url);
export const postJson = (url, body) => request('POST', url, body);

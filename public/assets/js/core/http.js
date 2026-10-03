/**
 * Small fetch() wrapper shared by every page script.
 *
 * - Sends cookies (same-origin) and asks for JSON.
 * - Adds the CSRF token (from <meta name="csrf-token">) to state-changing requests.
 * - Redirects to the login page when the session has expired (401).
 * - Throws HttpError for any non-2xx answer, carrying the decoded JSON body.
 */

export class HttpError extends Error {
    /**
     * @param {number} status
     * @param {any} payload Decoded JSON body, or null.
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
    const init = { method, headers, credentials: 'same-origin' };

    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrfToken();
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        payload = null; // empty or non-JSON body
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

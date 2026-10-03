/**
 * Community API: one function per endpoint.
 *
 * Built on core/http.js, which sends the CSRF token header on POST/DELETE and
 * turns every failure (network, timeout, non-2xx) into an HttpError.
 * Each function returns the "data" object of the {"status":"success","data":{...}} envelope.
 *
 * Note what is NOT sent: no user id and no condominium id. The server takes
 * both from the session, so the client could not impersonate anyone even if it tried.
 */
import { getJson, postJson, deleteJson, HttpError } from '../core/http.js';

const BASE = '/api/community';
const postUrl = (id) => `${BASE}/posts/${encodeURIComponent(String(id))}`;

const unwrap = (payload) => payload?.data ?? {};

/** @param {{category?: string, offset?: number, anchor?: number}} params */
export async function listPosts({ category = '', offset = 0, anchor = 0 } = {}) {
    const query = new URLSearchParams({ offset: String(offset), anchor: String(anchor) });
    if (category) {
        query.set('category', category);
    }
    return unwrap(await getJson(`${BASE}/posts?${query}`));
}

export async function createPost(category, body) {
    return unwrap(await postJson(`${BASE}/posts`, { category, body }));
}

/** liked=true → POST (like), liked=false → DELETE (unlike). Returns {liked, likes_count}. */
export async function setLike(postId, liked) {
    const url = `${postUrl(postId)}/like`;
    return unwrap(liked ? await postJson(url, {}) : await deleteJson(url));
}

export async function listComments(postId) {
    return unwrap(await getJson(`${postUrl(postId)}/comments`));
}

export async function addComment(postId, body) {
    return unwrap(await postJson(`${postUrl(postId)}/comments`, { body }));
}

export async function reportPost(postId, reason, details) {
    return unwrap(await postJson(`${postUrl(postId)}/reports`, { reason, details }));
}

export async function deletePostAsModerator(postId) {
    return unwrap(await deleteJson(`${BASE}/moderation/posts/${encodeURIComponent(String(postId))}`));
}

export async function dismissReports(postId) {
    return unwrap(await postJson(`${BASE}/moderation/posts/${encodeURIComponent(String(postId))}/dismiss`, {}));
}

const FRIENDLY = {
    0: 'Sem conexão com o servidor. Verifique sua internet.',
    403: 'Você não tem permissão para fazer isso.',
    404: 'Esta publicação não está mais disponível.',
    419: 'Sua sessão expirou. Recarregue a página.',
    429: 'Calma! Muitas ações seguidas. Tente de novo em instantes.',
};

/**
 * A message safe to show in a toast. The server's "message" is preferred: the
 * API only returns user-facing text (internal errors are reduced to a generic 500 message).
 * @param {unknown} error
 */
export function errorMessage(error) {
    if (error instanceof HttpError) {
        const fromServer = error.payload?.message ?? error.payload?.error;
        if (typeof fromServer === 'string' && fromServer !== '') {
            return fromServer;
        }
        return FRIENDLY[error.status] ?? 'Algo deu errado. Tente novamente em instantes.';
    }
    return 'Algo deu errado. Tente novamente em instantes.';
}

export { HttpError };

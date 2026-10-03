/**
 * Render functions: data in, DOM out.
 *
 * SECURITY: everything that comes from the server (post text, comments,
 * names, category names) is written with textContent, or into attributes
 * that are never interpreted as HTML. The JSON holds raw text; escaping it
 * here as well would show "&amp;" to users, and innerHTML is never used.
 */

const relative = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });
const absolute = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'medium', timeStyle: 'short' });
const KNOWN_CATEGORIES = ['classifieds', 'lost_found', 'neighborhood_tips', 'pets'];

/** "há 5 minutos", "ontem", or a full date for anything older than a week. */
export function formatTime(iso) {
    const date = new Date(iso);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 45) return 'agora mesmo';
    if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour');
    if (abs < 604800) return relative.format(Math.round(seconds / 86400), 'day');
    return absolute.format(date);
}

/** Up to two initials for the avatar circle. */
export function initials(name) {
    const parts = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    const letters = parts.length > 1 ? parts[0][0] + parts[parts.length - 1][0] : (parts[0] ?? '?').slice(0, 2);
    return letters.toUpperCase();
}

/** Fills an avatar element: initials as text, a stable colour class picked from the name. */
export function fillAvatar(element, name) {
    let hash = 0;
    for (const char of String(name ?? '')) {
        hash = (hash * 31 + char.codePointAt(0)) >>> 0;
    }
    element.textContent = initials(name);
    element.classList.add(`c-avatar--c${hash % 6}`);
}

/** Builds one comment <li>. */
export function renderComment(comment) {
    const item = document.createElement('li');
    item.className = 'c-comment';
    item.dataset.commentId = String(comment.id);

    const avatar = document.createElement('span');
    avatar.className = 'c-avatar c-avatar--small';
    avatar.setAttribute('aria-hidden', 'true');
    fillAvatar(avatar, comment.author.name);

    const bubble = document.createElement('div');
    bubble.className = 'c-comment__bubble';

    const author = document.createElement('strong');
    author.textContent = comment.author.is_me ? `${comment.author.name} (você)` : comment.author.name;

    const body = document.createElement('p');
    body.textContent = comment.body;

    const time = document.createElement('time');
    time.dateTime = comment.created_at;
    time.textContent = formatTime(comment.created_at);

    bubble.append(author, body, time);
    item.append(avatar, bubble);
    return item;
}

/** Reflects a like state on a card (used for optimistic updates and reconciliation). */
export function setLikeState(card, liked, count) {
    const button = card.querySelector('[data-action="like"]');
    button.setAttribute('aria-pressed', String(liked));
    button.classList.toggle('is-active', liked);
    card.querySelector('[data-field="likes-label"]').textContent = liked ? 'Curtido' : 'Curtir';
    card.querySelector('[data-field="likes-count"]').textContent = String(Math.max(0, count));
    card.dataset.liked = String(liked);
    card.dataset.likesCount = String(Math.max(0, count));
}

/** Updates the comment counter and the "see all N comments" link. */
export function setCommentsCount(card, total) {
    card.dataset.commentsCount = String(total);
    card.querySelector('[data-field="comments-count"]').textContent = String(total);
    const shown = card.querySelectorAll('[data-field="comments"] > li').length;
    const link = card.querySelector('[data-action="load-comments"]');
    link.hidden = total <= shown;
    link.textContent = `Ver todos os ${total} comentários`;
}

/** Marks a post as already reported by the viewer. */
export function setReported(card) {
    const button = card.querySelector('[data-action="report"]');
    button.disabled = true;
    button.classList.add('is-done');
    card.querySelector('[data-field="report-label"]').textContent = 'Denunciado';
}

/**
 * Builds a post card from the template in community/index.php.
 * @param {HTMLTemplateElement} template
 * @param {object} post JSON shape produced by CommunityController::presentPost()
 */
export function renderPost(template, post) {
    const card = template.content.firstElementChild.cloneNode(true);
    const field = (name) => card.querySelector(`[data-field="${name}"]`);

    card.dataset.postId = String(post.id);
    fillAvatar(field('avatar'), post.author.name);
    field('author').textContent = post.author.is_me ? `${post.author.name} (você)` : post.author.name;

    const time = field('time');
    time.dateTime = post.created_at;
    time.textContent = formatTime(post.created_at);
    time.title = absolute.format(new Date(post.created_at));

    const badge = field('category');
    badge.textContent = post.category.name;
    // Only known codes become CSS classes; anything else keeps the neutral badge.
    if (KNOWN_CATEGORIES.includes(post.category.code)) {
        badge.classList.add(`c-badge--${post.category.code}`);
    }

    field('body').textContent = post.body;

    const commentInput = card.querySelector('[data-comment-form] input[name="body"]');
    commentInput.placeholder = 'Escreva um comentário…';
    commentInput.maxLength = Number(document.getElementById('feed')?.dataset.commentMax ?? 1000);

    const list = field('comments');
    list.replaceChildren(...post.latest_comments.map(renderComment));

    setLikeState(card, post.liked, post.likes_count);
    setCommentsCount(card, post.comments_count);

    if (!post.can_report) {
        card.querySelector('[data-action="report"]').remove(); // own post
    } else if (post.reported) {
        setReported(card);
    }

    return card;
}

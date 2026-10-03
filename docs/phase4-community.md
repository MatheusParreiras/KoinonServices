# Koinon: Phase 4 Community (Social Network)

The Community tab: post feed, likes, comments, reports and moderation, built on the Phase 2/3 foundation.

This document is generated from the files in the repository. Verification so far:

- All PHP passes `php -l` (PHP 8.4), and all JavaScript passes `node --check`.
- Both views were rendered with hostile sample data: `<script>`, `<img onerror>`, and `<b>` or `"<x>"` in names all came out escaped, and nothing was double-escaped.
- `cleanText()`, malformed-JSON detection, initials and relative time were unit-checked.
- Every route was smoke-tested for 302/401/404/405 and the JSON error envelope.
- **No query has run against a live MySQL database**, and no card was rendered in a real browser. Test the flows before relying on them.

## Assumptions

| # | Decision |
|---|----------|
| A-01 | **Categories follow the Phase 1 schema**, as the brief instructs when they differ. The schema has Classifieds (`classifieds`), Lost & Found (`lost_found`), Neighborhood Tips (`neighborhood_tips`, the brief's "Tips") and Pets (`pets`). **There is no "General" category.** Adding one is a one-line `INSERT INTO social_categories`, which needs no code change because the allowlist is read from that table. |
| A-02 | **Who uses the Community tab: Residents and Property Managers.** Phase 1 grants `social.use` to residents and managers, and Concierge staff do not use the social network. **Moderators: Property Managers only.** The **Super Admin** can open the moderation view in read-only mode, the same as in Phase 3. A Super Admin has no membership, so the schema cannot record them as the reviewer of a report (`reviewed_by_user_id` references `condominium_users`). |
| A-03 | **Identity and tenant.** The actor is always `Auth::id()` (from `$_SESSION['user_id']`, re-validated against `users` on every request). The tenant is always `TenantContext::id()` (from `$_SESSION['condominium_id']`, re-validated by `TenantMiddleware`). The client never sends either one; `community/api.js` has no field for them. |
| A-04 | **The like toggle is two idempotent endpoints**: `POST …/like` sets the like and `DELETE …/like` removes it. The client states the state it wants, so a duplicated request cannot flip the result. The database `PRIMARY KEY (condominium_id, post_id, user_id)` allows only one like per person. Inserts use `INSERT … ON DUPLICATE KEY UPDATE` and check affected rows. Unlike `INSERT IGNORE`, this does not hide foreign-key or other errors. |
| A-05 | **Counters.** `posts.like_count`, `comment_count` and `report_count` (the Phase 1 denormalised columns) are the counts. They change only inside a transaction that holds the post's row lock (`SELECT … FOR UPDATE`), so they stay exact under concurrent clicks, and the feed never runs `COUNT(*)`. |
| A-06 | **Pagination** uses `LIMIT :limit OFFSET :offset` with integers bound as `PDO::PARAM_INT`, as the brief requires. It is pinned to an **anchor** (`p.id <= :anchor`, the newest post id at first load). Posts published while someone is scrolling therefore do not shift the offsets, and "Load more" never repeats or skips a post. Pages hold 10 posts; the query fetches 11 to know whether another page exists. |
| A-07 | **Moderation "delete" is a real `DELETE`.** The brief asks that likes, comments and reports "go with it", and the schema's `ON DELETE CASCADE` foreign keys (post_likes, post_comments, post_images, post_reports) do exactly that. This replaces the Phase 1 soft-delete note for moderated posts. Because the content is gone afterwards, an `audit_logs` entry is written in the same transaction first. It records the author id, the category, the counts and the report reasons, but **not** the post text (data minimisation). |
| A-08 | **Auto-hide** (Phase 1, A-12): a post with **3 pending reports** is set to `hidden` and leaves the feed until a manager reviews it. Dismissing the reports puts it back, and deleting removes it. Reporters are not told that a post was hidden. |
| A-09 | **Reports.** Reasons come from the schema ENUM: spam, offensive, harassment, scam, other. Details are optional, up to 500 characters. Authors cannot report their own posts (422). A second report by the same person returns **409**, handled through the `UNIQUE (condominium_id, post_id, reporter_user_id)` key. The moderation view does not show **who** reported, so neighbours who report are protected from retaliation. |
| A-10 | **Lengths:** posts can have 1–2000 characters (Phase 1, FR-SOC-02) and comments 1–1000 (`VARCHAR(1000)`). Text is stored as typed, after converting CRLF to LF, stripping invisible control characters and trimming. It is **escaped only at output**: by `e()` in PHP views, and by `textContent` in JavaScript. JSON carries raw text, so nothing is escaped twice. |
| A-11 | **Rate limits** (Phase 1, NFR-SEC-09): each session can make 10 posts or comments per minute and 10 reports per 10 minutes. Above that the API answers **429**, which the brief's status list does not mention but which is the correct code. |
| A-12 | **Feed cards are built in the browser** from JSON, by cloning a static `<template>` defined in the PHP view and filling it with `textContent`. The card markup is defined once, and user text never passes through `innerHTML`. The **moderation view is rendered on the server** and prints every value through `e()`. |
| A-13 | **Out of scope for this brief, and left for later:** images in posts (the `post_images` table exists), editing or deleting your own post or comment, and moderating individual comments. |

---

## 0. Foundation changes (Phase 2/3 files)

These are small, backward-compatible additions:

- **`app/Core/Router.php`** gains `delete()`, used for unlike and moderator deletion.
- **`app/Core/Request.php`** detects a malformed JSON body, which the API answers with **400**.
- **`app/Core/Controller.php`** gains `success()` and `failure()`, which produce the JSON envelope. Phase 3's `invalid()` now uses the same envelope.
- **`app/Core/ErrorHandler.php`** gives 401/403/404/419/500 errors from middleware the same envelope. The legacy `error` key is kept, so Phase 2/3 scripts keep working.
- **`public/assets/js/core/http.js`** gains `deleteJson()`.
- **`app/Core/Navigation.php`** adds the Community link. The Super Admin gets a "Moderação" link instead.
- **New: `app/Core/RateLimiter.php`.**

```php
// … excerpt from app/Core/Router.php
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string> $middleware
     */
    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }
```

```php
// … excerpt from app/Core/Request.php
        $malformed = false;
        if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $raw = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES);
            $decoded = json_decode($raw === false ? '' : $raw, true);
            $body = is_array($decoded) ? $decoded : [];
            // An empty body is fine (e.g. a like); a non-empty one that is not a JSON object is not.
            $malformed = $raw !== false && trim($raw) !== '' && !is_array($decoded);
        }

        return new self($method, $path, $_GET, $body, $_SERVER, $malformed);
```

```php
// … excerpt from app/Core/Request.php
    /** True when a JSON body was sent but could not be decoded into an object (answer 400). */
    public function hasMalformedJson(): bool
    {
        return $this->malformedJson;
    }
```

```php
// … excerpt from app/Core/Controller.php
    /**
     * Standard JSON success envelope: {"status": "success", "data": {...}}.
     *
     * @param array<string, mixed> $data
     */
    protected function success(array $data, int $status = 200): Response
    {
        return Response::json(['status' => 'success', 'data' => $data], $status);
    }
```

```php
// … excerpt from app/Core/Controller.php
    /**
     * Standard JSON error envelope: {"status": "error", "message": "...", "errors": {...}}.
     * "error" repeats the message for clients written against the Phase 2/3 shape.
     *
     * @param array<string, string> $errors field => message (validation errors only)
     */
    protected function failure(string $message, int $status, array $errors = []): Response
    {
        $body = ['status' => 'error', 'message' => $message, 'error' => $message];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return Response::json($body, $status);
    }
```

```php
// … excerpt from app/Core/ErrorHandler.php
        if ($request->wantsJson()) {
            return Response::json(['status' => 'error', 'message' => $message, 'error' => $message], $status);
        }
```

`app/Core/RateLimiter.php`

```php
<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sliding-window rate limiter stored in the user's session.
 *
 * Enough to stop a resident (or a script using their session) from flooding
 * the feed (Phase 1, NFR-SEC-09). It is per session, not per IP; abuse at the
 * network level belongs to the web server or a WAF.
 */
final class RateLimiter
{
    private const SESSION_KEY = '_rate_limits';

    /**
     * Records an attempt for $key and reports whether it is allowed.
     *
     * @return bool False when $max attempts already happened in the last $seconds.
     */
    public static function attempt(string $key, int $max, int $seconds): bool
    {
        $now = time();
        $buckets = Session::get(self::SESSION_KEY, []);
        $hits = array_values(array_filter(
            is_array($buckets[$key] ?? null) ? $buckets[$key] : [],
            static fn (mixed $t): bool => is_int($t) && $t > $now - $seconds
        ));

        if (count($hits) >= $max) {
            return false;
        }

        $hits[] = $now;
        $buckets[$key] = $hits;
        Session::set(self::SESSION_KEY, $buckets);

        return true;
    }
}
```


---

## 1. Core Models (PHP PDO)

Where the two identifiers are applied:

| Identifier | Source | Applied in |
|------------|--------|------------|
| `condominium_id` | `TenantContext::id()` ← session, re-validated per request | `:tenant` in **every** query through `TenantModel::scoped()`; `TenantModel::insert()` overwrites any value from the client; joins repeat `x.condominium_id = p.condominium_id`. The composite foreign keys (post_likes, post_comments, post_reports → posts) reject cross-tenant rows in the database itself. |
| `user_id` (actor) | `Auth::id()` ← session | The service layer receives it as `$actorId` from the controller and writes it to `author_user_id`, `post_likes.user_id` and `reporter_user_id`. `liked_by_me` and `reported_by_me` are computed against it. `PostLike::remove()` deletes only the row with **that** user id. |

`app/Models/SocialCategory.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Social network categories (`social_categories`): a GLOBAL allowlist shared by
 * every condominium (Phase 1 seed: classifieds, lost_found, neighborhood_tips, pets).
 *
 * Posts may only use an active category from this table. The client sends a
 * code, and the server looks it up here; ids are never taken from the request.
 */
final class SocialCategory extends Model
{
    protected string $table = 'social_categories';

    /** @var list<array{id: int, code: string, name: string}>|null Per-request cache. */
    private static ?array $cache = null;

    /**
     * Active categories in display order.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function active(): array
    {
        return self::$cache ??= array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'code' => $row['code'], 'name' => $row['name']],
            $this->fetchAll('SELECT id, code, name FROM social_categories WHERE is_active = 1 ORDER BY sort_order, name')
        );
    }

    /** The active category with this code, or null (the allowlist check). */
    public function findByCode(string $code): ?array
    {
        foreach ($this->active() as $category) {
            if ($category['code'] === $code) {
                return $category;
            }
        }

        return null;
    }
}
```

`app/Models/Post.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;
use InvalidArgumentException;

/**
 * Social network posts (`posts`), tenant-scoped.
 *
 * Every query contains "p.condominium_id = :tenant" with the value from
 * scoped(), which is TenantContext::id(), resolved from the session. A post id
 * from another condominium is therefore never found, liked, commented on or reported.
 *
 * like_count, comment_count and report_count are denormalised counters. They
 * change only inside a transaction that holds the post's row lock (see
 * lockPublished()), so they stay equal to the real number of rows.
 */
final class Post extends TenantModel
{
    public const BODY_MAX = 2000;

    protected string $table = 'posts';

    protected array $fillable = ['author_user_id', 'category_id', 'body'];

    /**
     * Columns shared by the feed and single-card queries.
     *
     * liked_by_me / reported_by_me use the ACTOR's id (:viewer_a / :viewer_b,
     * both bound to the session user id). A client cannot ask "as user X".
     * Two placeholders are needed because native prepared statements cannot
     * reuse a named parameter.
     */
    private const CARD_SELECT = <<<'SQL'
        SELECT p.id, p.body, p.created_at, p.edited_at, p.like_count, p.comment_count,
               p.author_user_id, u.full_name AS author_name,
               c.code AS category_code, c.name AS category_name,
               EXISTS (SELECT 1 FROM post_likes pl
                        WHERE pl.condominium_id = p.condominium_id
                          AND pl.post_id = p.id AND pl.user_id = :viewer_a) AS liked_by_me,
               EXISTS (SELECT 1 FROM post_reports pr
                        WHERE pr.condominium_id = p.condominium_id
                          AND pr.post_id = p.id AND pr.reporter_user_id = :viewer_b) AS reported_by_me
          FROM posts p
          JOIN users u             ON u.id = p.author_user_id
          JOIN social_categories c ON c.id = p.category_id
         WHERE p.condominium_id = :tenant
           AND p.status = 'published'
        SQL;

    /**
     * One page of the feed, newest first.
     *
     * Pagination is LIMIT/OFFSET (bound as integers) under a fixed anchor:
     * "p.id <= :anchor" pins the result set to the posts that existed when the
     * user opened the feed. New posts published meanwhile do not shift the
     * offsets, so "Load more" never repeats or skips a post. Fetch $limit + 1
     * rows to know whether another page exists. Served by ix_posts_feed /
     * ix_posts_category_feed.
     *
     * @return list<array<string, mixed>>
     */
    public function feed(int $viewerId, ?int $categoryId, int $anchorId, int $limit, int $offset): array
    {
        $sql = self::CARD_SELECT . ' AND p.id <= :anchor';
        $params = $this->scoped([
            'viewer_a' => $viewerId,
            'viewer_b' => $viewerId,
            'anchor'   => $anchorId,
            'limit'    => $limit,   // int -> bound as PDO::PARAM_INT (Model::run)
            'offset'   => $offset,
        ]);
        if ($categoryId !== null) {
            $sql .= ' AND p.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        return $this->fetchAll($sql . ' ORDER BY p.id DESC LIMIT :limit OFFSET :offset', $params);
    }

    /** Highest published post id of the tenant: the anchor of a fresh feed. */
    public function latestId(): int
    {
        $row = $this->fetchOne(
            "SELECT COALESCE(MAX(id), 0) AS max_id FROM posts WHERE condominium_id = :tenant AND status = 'published'",
            $this->scoped()
        );

        return (int) ($row['max_id'] ?? 0);
    }

    /** One published post in card format (after creating it), seen by $viewerId. */
    public function findCard(int $id, int $viewerId): ?array
    {
        return $this->fetchOne(
            self::CARD_SELECT . ' AND p.id = :id',
            $this->scoped(['viewer_a' => $viewerId, 'viewer_b' => $viewerId, 'id' => $id])
        );
    }

    /**
     * THE TENANT CHECK BEFORE ANY INTERACTION: loads the post only if it belongs
     * to the current condominium AND is published, and locks its row until the
     * transaction ends.
     *
     * The lock serialises all likes, comments and reports of one post, which
     * keeps the counters exact under concurrent clicks. A null result means the
     * post does not exist, belongs to another condominium, or was removed. The
     * caller answers 404 in all three cases.
     */
    public function lockPublished(int $id): ?array
    {
        return $this->fetchOne(
            "SELECT id, author_user_id, status, like_count, comment_count, report_count
               FROM posts
              WHERE id = :id AND condominium_id = :tenant AND status = 'published'
              FOR UPDATE",
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Adds $delta to one counter. The column name is chosen from a whitelist,
     * never from input, because identifiers cannot be bound as parameters.
     * GREATEST() keeps an UNSIGNED counter from underflowing.
     */
    public function adjustCounter(int $id, string $counter, int $delta): void
    {
        $column = match ($counter) {
            'like_count', 'comment_count', 'report_count' => $counter,
            default => throw new InvalidArgumentException("Unknown counter {$counter}."),
        };

        $this->execute(
            "UPDATE posts SET {$column} = GREATEST(CAST({$column} AS SIGNED) + :delta, 0)
              WHERE id = :id AND condominium_id = :tenant",
            $this->scoped(['id' => $id, 'delta' => $delta])
        );
    }

    /** Hides a post pending review (automatic, after too many reports). */
    public function hide(int $id): void
    {
        $this->execute(
            "UPDATE posts SET status = 'hidden' WHERE id = :id AND condominium_id = :tenant AND status = 'published'",
            $this->scoped(['id' => $id])
        );
    }

    // ---------------------------------------------------------------- moderation

    /**
     * Posts of this condominium with PENDING reports, most reported first.
     * Includes auto-hidden posts, which are waiting for exactly this review.
     *
     * @return list<array<string, mixed>>
     */
    public function reportedForModeration(): array
    {
        return $this->fetchAll(
            "SELECT p.id, p.body, p.status, p.created_at, p.like_count, p.comment_count,
                    u.full_name AS author_name, c.name AS category_name, c.code AS category_code,
                    COUNT(r.id) AS pending_reports,
                    MAX(r.created_at) AS last_reported_at
               FROM posts p
               JOIN post_reports r
                 ON r.condominium_id = p.condominium_id AND r.post_id = p.id AND r.status = 'pending'
               JOIN users u             ON u.id = p.author_user_id
               JOIN social_categories c ON c.id = p.category_id
              WHERE p.condominium_id = :tenant
                AND p.status IN ('published', 'hidden')
              GROUP BY p.id, p.body, p.status, p.created_at, p.like_count, p.comment_count,
                       u.full_name, c.name, c.code
              ORDER BY pending_reports DESC, last_reported_at DESC
              LIMIT 100",
            $this->scoped()
        );
    }

    /** Locks a post of this tenant for a moderation decision (any status). */
    public function lockForModeration(int $id): ?array
    {
        return $this->fetchOne(
            'SELECT id, author_user_id, category_id, status, like_count, comment_count, report_count
               FROM posts WHERE id = :id AND condominium_id = :tenant FOR UPDATE',
            $this->scoped(['id' => $id])
        );
    }

    /**
     * Deletes a post of this tenant. The schema's ON DELETE CASCADE removes its
     * likes, comments, images and reports in the same statement.
     */
    public function deleteForModeration(int $id): bool
    {
        return $this->execute(
            'DELETE FROM posts WHERE id = :id AND condominium_id = :tenant',
            $this->scoped(['id' => $id])
        ) === 1;
    }

    /** After reports are dismissed: back to published, pending counter reset, moderator recorded. */
    public function restoreAfterDismissal(int $id, int $moderatorId): void
    {
        $this->execute(
            "UPDATE posts
                SET status = 'published', report_count = 0,
                    moderated_by_user_id = :moderator_id, moderated_at = UTC_TIMESTAMP()
              WHERE id = :id AND condominium_id = :tenant AND status IN ('published', 'hidden')",
            $this->scoped(['id' => $id, 'moderator_id' => $moderatorId])
        );
    }
}
```

`app/Models/PostLike.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Likes (`post_likes`). PRIMARY KEY (condominium_id, post_id, user_id) makes a
 * second like by the same person impossible at the database level.
 */
final class PostLike extends TenantModel
{
    protected string $table = 'post_likes';

    /**
     * Adds the like of $userId (always the session user, never a client value).
     *
     * ON DUPLICATE KEY UPDATE turns a repeated like into a no-op: affected rows
     * are 1 for a new like and 0 when it already existed. Unlike INSERT IGNORE,
     * it does not silence other errors (e.g. a foreign-key failure).
     *
     * @return bool True when a new like was created.
     */
    public function add(int $postId, int $userId): bool
    {
        return $this->execute(
            'INSERT INTO post_likes (condominium_id, post_id, user_id)
             VALUES (:tenant, :post_id, :user_id)
             ON DUPLICATE KEY UPDATE created_at = created_at',
            $this->scoped(['post_id' => $postId, 'user_id' => $userId])
        ) === 1;
    }

    /**
     * Removes the like of $userId. All three key columns are in the WHERE, so a
     * user can only ever remove their own like, in their own condominium.
     *
     * @return bool True when a like was removed.
     */
    public function remove(int $postId, int $userId): bool
    {
        return $this->execute(
            'DELETE FROM post_likes
              WHERE condominium_id = :tenant AND post_id = :post_id AND user_id = :user_id',
            $this->scoped(['post_id' => $postId, 'user_id' => $userId])
        ) === 1;
    }
}
```

`app/Models/PostComment.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Comments (`post_comments`), tenant-scoped.
 */
final class PostComment extends TenantModel
{
    public const BODY_MAX = 1000;

    protected string $table = 'post_comments';

    protected array $fillable = ['post_id', 'author_user_id', 'body'];

    private const SELECT = 'SELECT c.id, c.post_id, c.body, c.created_at, c.author_user_id, u.full_name AS author_name
          FROM post_comments c
          JOIN users u ON u.id = c.author_user_id ';

    /**
     * The latest $perPost visible comments of EACH post in $postIds, in ONE query.
     *
     * ROW_NUMBER() numbers each post's comments newest-first, and the outer query
     * keeps numbers 1..$perPost. This avoids running one query per post on the
     * page (the "N+1" problem). Served by ix_post_comments_thread
     * (condominium_id, post_id, status, id). The IN list has one bound
     * placeholder per id (Model::inList), never concatenated values.
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => comments, oldest first
     */
    public function latestForPosts(array $postIds, int $perPost = 3): array
    {
        if ($postIds === []) {
            return [];
        }
        [$placeholders, $idParams] = $this->inList('post', $postIds);

        $rows = $this->fetchAll(
            "SELECT id, post_id, body, created_at, author_user_id, author_name
               FROM (
                    SELECT c.id, c.post_id, c.body, c.created_at, c.author_user_id, u.full_name AS author_name,
                           ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.id DESC) AS rn
                      FROM post_comments c
                      JOIN users u ON u.id = c.author_user_id
                     WHERE c.condominium_id = :tenant
                       AND c.status = 'visible'
                       AND c.post_id IN ({$placeholders})
               ) ranked
              WHERE rn <= :per_post
              ORDER BY post_id, id",
            $this->scoped(['per_post' => $perPost] + $idParams)
        );

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row['post_id']][] = $row;
        }

        return $byPost;
    }

    /**
     * Every visible comment of one post (the "see all comments" link).
     * The caller must first confirm that the post is visible in this tenant.
     *
     * @return list<array<string, mixed>>
     */
    public function forPost(int $postId, int $limit = 200): array
    {
        return $this->fetchAll(
            self::SELECT . "WHERE c.condominium_id = :tenant AND c.post_id = :post_id AND c.status = 'visible'
              ORDER BY c.id LIMIT :limit",
            $this->scoped(['post_id' => $postId, 'limit' => $limit])
        );
    }

    /** One comment with author name and timestamp (the response after creating it). */
    public function findWithAuthor(int $id): ?array
    {
        return $this->fetchOne(
            self::SELECT . 'WHERE c.condominium_id = :tenant AND c.id = :id',
            $this->scoped(['id' => $id])
        );
    }
}
```

`app/Models/PostReport.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\TenantModel;

/**
 * Reports (`post_reports`). UNIQUE (condominium_id, post_id, reporter_user_id)
 * allows one report per person per post.
 */
final class PostReport extends TenantModel
{
    public const REASONS = [
        'spam'       => 'Spam ou propaganda',
        'offensive'  => 'Conteúdo ofensivo',
        'harassment' => 'Assédio ou intimidação',
        'scam'       => 'Golpe ou fraude',
        'other'      => 'Outro motivo',
    ];

    protected string $table = 'post_reports';

    /**
     * Files a report by $reporterId (the session user).
     *
     * ON DUPLICATE KEY UPDATE makes "already reported" an ordinary outcome
     * (0 affected rows) instead of an exception, and still reports any other
     * error, unlike INSERT IGNORE.
     *
     * @return bool True for a new report, false when this user had already reported the post.
     */
    public function file(int $postId, int $reporterId, string $reason, ?string $details): bool
    {
        return $this->execute(
            'INSERT INTO post_reports (condominium_id, post_id, reporter_user_id, reason, details)
             VALUES (:tenant, :post_id, :reporter_id, :reason, :details)
             ON DUPLICATE KEY UPDATE id = id',
            $this->scoped([
                'post_id'     => $postId,
                'reporter_id' => $reporterId,
                'reason'      => $reason,
                'details'     => $details,
            ])
        ) === 1;
    }

    /**
     * Pending reports of several posts, for the moderation view.
     *
     * The reporter's identity is deliberately NOT selected: the moderator needs
     * the reason, not who complained (data minimisation, and it protects
     * reporters from retaliation by neighbours).
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => reports
     */
    public function pendingForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        [$placeholders, $idParams] = $this->inList('post', $postIds);

        $rows = $this->fetchAll(
            "SELECT id, post_id, reason, details, created_at
               FROM post_reports
              WHERE condominium_id = :tenant
                AND status = 'pending'
                AND post_id IN ({$placeholders})
              ORDER BY created_at",
            $this->scoped($idParams)
        );

        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(int) $row['post_id']][] = $row;
        }

        return $byPost;
    }

    /** Marks every pending report of a post as dismissed by $moderatorId. */
    public function dismissPending(int $postId, int $moderatorId): int
    {
        return $this->execute(
            "UPDATE post_reports
                SET status = 'dismissed', reviewed_by_user_id = :moderator_id, reviewed_at = UTC_TIMESTAMP()
              WHERE condominium_id = :tenant AND post_id = :post_id AND status = 'pending'",
            $this->scoped(['post_id' => $postId, 'moderator_id' => $moderatorId])
        );
    }

    /**
     * Reason counts of a post's pending reports (kept in the audit log when the post is deleted).
     *
     * @return array<string, int>
     */
    public function pendingReasonCounts(int $postId): array
    {
        $rows = $this->fetchAll(
            "SELECT reason, COUNT(*) AS total FROM post_reports
              WHERE condominium_id = :tenant AND post_id = :post_id AND status = 'pending'
              GROUP BY reason",
            $this->scoped(['post_id' => $postId])
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['reason']] = (int) $row['total'];
        }

        return $counts;
    }
}
```


### Services: transactions and the tenant check before every interaction

`app/Services/CommunityService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostReport;

/**
 * Social network interactions: create, like/unlike, comment, report.
 *
 * Every interaction with an existing post follows the same pattern, inside one
 * transaction:
 *   1. Post::lockPublished($postId): the TENANT CHECK. It returns the post only
 *      if it belongs to the session's condominium and is published, and locks
 *      it. Otherwise 404.
 *   2. Write the like/comment/report with $actorId, the SESSION user id passed
 *      in by the controller. No user id is ever read from the request.
 *   3. Adjust the post's counter while the lock is held, so counts stay exact.
 */
final class CommunityService
{
    /** Pending reports that automatically hide a post until a manager reviews it (Phase 1, A-12). */
    public const AUTO_HIDE_THRESHOLD = 3;

    public function __construct(
        private readonly Post $posts = new Post(),
        private readonly PostLike $likes = new PostLike(),
        private readonly PostComment $comments = new PostComment(),
        private readonly PostReport $reports = new PostReport()
    ) {
    }

    /**
     * Publishes a post and returns it in card format.
     * $categoryId has already been resolved from the allowlist by the controller.
     *
     * @return array<string, mixed>
     */
    public function createPost(int $actorId, int $categoryId, string $body): array
    {
        $id = $this->posts->insert([
            'author_user_id' => $actorId,  // the session user, never a client value
            'category_id'    => $categoryId,
            'body'           => $body,     // raw text; escaped at output time only
        ]);

        return (array) $this->posts->findCard($id, $actorId);
    }

    /**
     * Sets the actor's like on or off. Idempotent: liking twice keeps one like
     * (primary key), and unliking twice removes nothing the second time. The
     * counter only moves when a row was really inserted or deleted.
     *
     * @return array{liked: bool, likes_count: int} The authoritative state.
     * @throws BusinessRuleException 404 when the post is not visible in this condominium.
     */
    public function setLike(int $postId, int $actorId, bool $like): array
    {
        return Database::transaction(function () use ($postId, $actorId, $like): array {
            $post = $this->lockPublished($postId);
            $count = (int) $post['like_count'];

            if ($like && $this->likes->add($postId, $actorId)) {
                $this->posts->adjustCounter($postId, 'like_count', +1);
                $count++;
            } elseif (!$like && $this->likes->remove($postId, $actorId)) {
                $this->posts->adjustCounter($postId, 'like_count', -1);
                $count = max(0, $count - 1);
            }

            // $count is exact: the row lock guarantees nobody changed it since we read it.
            return ['liked' => $like, 'likes_count' => $count];
        });
    }

    /**
     * Adds a comment by the actor and returns it with author name and timestamp.
     *
     * @return array{comment: array<string, mixed>, comments_count: int}
     * @throws BusinessRuleException 404
     */
    public function addComment(int $postId, int $actorId, string $body): array
    {
        return Database::transaction(function () use ($postId, $actorId, $body): array {
            $post = $this->lockPublished($postId);

            // TenantModel::insert() sets condominium_id from the session's tenant,
            // and the composite FK (condominium_id, post_id) proves the post is in it.
            $commentId = $this->comments->insert([
                'post_id'        => $postId,
                'author_user_id' => $actorId,
                'body'           => $body,
            ]);
            $this->posts->adjustCounter($postId, 'comment_count', +1);

            return [
                'comment'        => (array) $this->comments->findWithAuthor($commentId),
                'comments_count' => (int) $post['comment_count'] + 1,
            ];
        });
    }

    /**
     * Visible comments of a post, after the tenant check (no lock needed for reading).
     *
     * @return list<array<string, mixed>>
     * @throws BusinessRuleException 404
     */
    public function allComments(int $postId): array
    {
        $post = $this->posts->find($postId); // TenantModel::find(): id AND condominium_id
        if ($post === null || $post['status'] !== 'published') {
            throw new BusinessRuleException('Publicação não encontrada.', 404);
        }

        return $this->comments->forPost($postId);
    }

    /**
     * Reports a post on behalf of the actor.
     *
     * @return array{hidden: bool} Whether the post was auto-hidden by this report.
     * @throws BusinessRuleException 404 not visible, 409 already reported, 422 own post
     */
    public function report(int $postId, int $actorId, string $reason, ?string $details): array
    {
        return Database::transaction(function () use ($postId, $actorId, $reason, $details): array {
            $post = $this->lockPublished($postId);

            if ((int) $post['author_user_id'] === $actorId) {
                throw new BusinessRuleException('Você não pode denunciar a sua própria publicação.', 422);
            }
            if (!$this->reports->file($postId, $actorId, $reason, $details)) {
                throw new BusinessRuleException('Você já denunciou esta publicação. A administração vai analisar.', 409);
            }

            $this->posts->adjustCounter($postId, 'report_count', +1);
            $hidden = (int) $post['report_count'] + 1 >= self::AUTO_HIDE_THRESHOLD;
            if ($hidden) {
                $this->posts->hide($postId);
            }

            return ['hidden' => $hidden];
        });
    }

    /**
     * @return array<string, mixed>
     * @throws BusinessRuleException 404
     */
    private function lockPublished(int $postId): array
    {
        return $this->posts->lockPublished($postId)
            ?? throw new BusinessRuleException('Publicação não encontrada ou removida.', 404);
    }
}
```

`app/Services/ModerationService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\PostReport;

/**
 * Moderation decisions on reported posts. Callers must already have checked the
 * moderator role; every query here is scoped to the current condominium.
 */
final class ModerationService
{
    public function __construct(
        private readonly Post $posts = new Post(),
        private readonly PostReport $reports = new PostReport(),
        private readonly AuditLog $audit = new AuditLog()
    ) {
    }

    /**
     * Reported posts with their pending reports, for the moderation view.
     *
     * @return list<array<string, mixed>> each post has a 'reports' list
     */
    public function queue(): array
    {
        $posts = $this->posts->reportedForModeration();
        $reports = $this->reports->pendingForPosts(array_map(static fn (array $p): int => (int) $p['id'], $posts));

        foreach ($posts as &$post) {
            $post['reports'] = $reports[(int) $post['id']] ?? [];
        }
        unset($post); // break the reference left by foreach

        return $posts;
    }

    /**
     * Deletes a post. Likes, comments, images and reports go with it through the
     * schema's ON DELETE CASCADE.
     *
     * The post's text is gone after this, so a summary is written to the audit
     * log first, inside the same transaction: who wrote it, how many reports
     * and for which reasons. The text itself is not kept.
     *
     * @throws BusinessRuleException 404 when the post is not in this condominium.
     */
    public function deletePost(int $postId, int $moderatorId, Request $request): void
    {
        Database::transaction(function () use ($postId, $moderatorId, $request): void {
            $post = $this->posts->lockForModeration($postId)
                ?? throw new BusinessRuleException('Publicação não encontrada.', 404);

            $this->audit->record(
                'community.post_deleted',
                $request,
                $moderatorId,
                TenantContext::id(),
                'post',
                $postId,
                [
                    'author_user_id' => (int) $post['author_user_id'],
                    'category_id'    => (int) $post['category_id'],
                    'likes'          => (int) $post['like_count'],
                    'comments'       => (int) $post['comment_count'],
                    'report_reasons' => $this->reports->pendingReasonCounts($postId),
                ]
            );

            $this->posts->deleteForModeration($postId);
        });
    }

    /**
     * Dismisses all pending reports of a post and puts it back in the feed if it
     * had been auto-hidden.
     *
     * @return int Number of reports dismissed.
     * @throws BusinessRuleException 404 not in this condominium, 409 nothing pending
     */
    public function dismissReports(int $postId, int $moderatorId, Request $request): int
    {
        return Database::transaction(function () use ($postId, $moderatorId, $request): int {
            $this->posts->lockForModeration($postId)
                ?? throw new BusinessRuleException('Publicação não encontrada.', 404);

            $dismissed = $this->reports->dismissPending($postId, $moderatorId);
            if ($dismissed === 0) {
                throw new BusinessRuleException('Esta publicação não tem denúncias pendentes.', 409);
            }

            $this->posts->restoreAfterDismissal($postId, $moderatorId);
            $this->audit->record(
                'community.reports_dismissed',
                $request,
                $moderatorId,
                TenantContext::id(),
                'post',
                $postId,
                ['dismissed' => $dismissed]
            );

            return $dismissed;
        });
    }
}
```


---

## 2. Controllers (JSON Endpoints)

**Envelope.** Every response has `Content-Type: application/json; charset=UTF-8`. Success looks like `{"status":"success","data":{…}}`; errors look like `{"status":"error","message":"…","errors"?:{field:msg}}`.

**Check order in every write action:**

1. **CSRF**: the `csrf` route middleware, using the `X-CSRF-Token` header.
2. **Role**: the `role:` middleware, plus `requireRole()` in the action.
3. **400**: malformed JSON.
4. **429**: rate limit.
5. **422**: validation and allowlists.
6. **Tenant**: the service locks the post `WHERE id = ? AND condominium_id = :tenant`, and answers 404 when it is not found.
7. **409**: conflicts with the current state.
8. **200/201**: success.

| Method & path | Roles | Success | Errors |
|---------------|-------|---------|--------|
| `GET /community` | manager, resident | page | 302 to login |
| `GET /api/community/posts?category=&offset=&anchor=` | manager, resident | 200 `{posts, has_more, next_offset, anchor}` | 422 unknown category or bad paging |
| `POST /api/community/posts` `{category, body}` | manager, resident | 201 `{post}` | 400, 422, 429 |
| `POST /api/community/posts/{id}/like` | manager, resident | 200 `{liked: true, likes_count}` | 404 |
| `DELETE /api/community/posts/{id}/like` | manager, resident | 200 `{liked: false, likes_count}` | 404 |
| `GET /api/community/posts/{id}/comments` | manager, resident | 200 `{comments}` | 404 |
| `POST /api/community/posts/{id}/comments` `{body}` | manager, resident | 201 `{comment, comments_count}` | 400, 404, 422, 429 |
| `POST /api/community/posts/{id}/reports` `{reason, details}` | manager, resident | 201 `{reported: true}` | 400, 404, **409 already reported**, 422 (also own post), 429 |
| `GET /community/moderation` | manager, Super Admin (read-only) | page | 403 |
| `DELETE /api/community/moderation/posts/{id}` | **manager** | 200 `{deleted: true}` | 403, 404 |
| `POST /api/community/moderation/posts/{id}/dismiss` | **manager** | 200 `{dismissed: n}` | 403, 404, 409 nothing pending |

All routes also answer 401 when not logged in and 419 for a bad CSRF token, both from middleware.

`app/Controllers/Community/CommunityController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Response;
use App\Services\BusinessRuleException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Shared base of the Community (social network) controllers.
 *
 * Every JSON endpoint answers with the same envelope:
 *   success: {"status": "success", "data": {...}}
 *   error:   {"status": "error", "message": "...", "errors"?: {field: message}}
 * with Content-Type: application/json (set by Response::json).
 *
 * JSON carries RAW text (no HTML escaping). The browser inserts it with
 * textContent, which never interprets markup. Escaping here as well would show
 * "&amp;" to users; escaping is the job of the output layer (e() in PHP views,
 * textContent in JS).
 */
abstract class CommunityController extends Controller
{
    /** May read the feed, post, like, comment and report (Phase 1: social.use). */
    public const MEMBERS = ['manager', 'resident'];

    /** May open the moderation view. The Super Admin can look but not act (see Assumptions). */
    public const MODERATION_VIEWERS = [Auth::SUPER_ADMIN, 'manager'];

    /** May delete posts and dismiss reports. */
    public const MODERATORS = ['manager'];

    /**
     * 400 when the client sent a JSON body that is not a JSON object.
     * Call at the start of every action that reads a body.
     */
    protected function malformedBody(): ?Response
    {
        return $this->request->hasMalformedJson()
            ? $this->failure('O corpo da requisição não é um JSON válido.', 400)
            : null;
    }

    /** Turns a business-rule failure (404/409/422) into the error envelope. */
    protected function ruleFailure(BusinessRuleException $e): Response
    {
        $errors = $e->field() !== null ? [$e->field() => $e->getMessage()] : [];

        return $this->failure($e->getMessage(), $e->status(), $errors);
    }

    /**
     * Normalises user text: CRLF becomes LF, invisible control characters are
     * removed (except line breaks and tabs), and the ends are trimmed. This is
     * NOT escaping: the text is stored as typed and escaped at output.
     */
    protected static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';

        return trim($text);
    }

    /**
     * Post row (Post::CARD_SELECT) to the JSON shape used by community/render.js.
     *
     * Only what the card shows is exposed: no user ids, just "is_me" flags
     * computed against the session user.
     *
     * @param array<string, mixed>             $post
     * @param list<array<string, mixed>>       $latestComments
     * @return array<string, mixed>
     */
    protected function presentPost(array $post, array $latestComments = []): array
    {
        $me = (int) Auth::id();
        $isMine = (int) $post['author_user_id'] === $me;

        return [
            'id'              => (int) $post['id'],
            'body'            => (string) $post['body'],
            'category'        => ['code' => (string) $post['category_code'], 'name' => (string) $post['category_name']],
            'author'          => ['name' => (string) $post['author_name'], 'is_me' => $isMine],
            'created_at'      => self::isoUtc((string) $post['created_at']),
            'likes_count'     => (int) $post['like_count'],
            'comments_count'  => (int) $post['comment_count'],
            'liked'           => (bool) $post['liked_by_me'],
            'reported'        => (bool) $post['reported_by_me'],
            'can_report'      => !$isMine,
            'latest_comments' => array_map([$this, 'presentComment'], $latestComments),
        ];
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    protected function presentComment(array $comment): array
    {
        return [
            'id'         => (int) $comment['id'],
            'post_id'    => (int) $comment['post_id'],
            'body'       => (string) $comment['body'],
            'author'     => [
                'name'  => (string) $comment['author_name'],
                'is_me' => (int) $comment['author_user_id'] === (int) Auth::id(),
            ],
            'created_at' => self::isoUtc((string) $comment['created_at']),
        ];
    }

    /** UTC DATETIME from MySQL → ISO 8601 with "Z", formatted in local time by the browser. */
    protected static function isoUtc(string $datetime): string
    {
        return (new DateTimeImmutable($datetime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
```

`app/Controllers/Community/PostController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostReport;
use App\Models\SocialCategory;
use App\Services\CommunityService;

/**
 * The Community tab: page shell, feed JSON, and post creation.
 */
final class PostController extends CommunityController
{
    private const PAGE_SIZE = 10;
    private const MAX_OFFSET = 5000;

    /** GET /community: the page. Cards are rendered by community/feed.js from the JSON feed. */
    public function index(): Response
    {
        $this->requireRole(self::MEMBERS);

        return $this->view('community/index', [
            'title'        => 'Comunidade',
            'categories'   => (new SocialCategory())->active(),
            'reasons'      => PostReport::REASONS,
            'bodyMax'      => Post::BODY_MAX,
            'commentMax'   => PostComment::BODY_MAX,
            'canModerate'  => Auth::hasRole(self::MODERATION_VIEWERS),
            'activeTab'    => 'feed',
            'showModerationTab' => Auth::hasRole(self::MODERATION_VIEWERS),
            'scripts'      => ['js/community/feed.js'],
        ], layout: 'layouts/community');
    }

    /**
     * GET /api/community/posts?category=pets&offset=10&anchor=123
     *
     * 200 {"status":"success","data":{"posts":[...],"has_more":bool,"next_offset":int,"anchor":int}}
     * 422 unknown category or invalid paging values.
     */
    public function feed(): Response
    {
        $this->requireRole(self::MEMBERS);

        // Category filter: only codes from the allowlist (social_categories) are accepted.
        $categoryId = null;
        $code = (string) $this->request->query('category', '');
        if ($code !== '') {
            $category = (new SocialCategory())->findByCode($code);
            if ($category === null) {
                return $this->failure('Categoria inválida.', 422, ['category' => 'Categoria inválida.']);
            }
            $categoryId = $category['id'];
        }

        $offset = filter_var($this->request->query('offset', '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_OFFSET],
        ]);
        $anchor = filter_var($this->request->query('anchor', '0'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        if ($offset === false || $anchor === false) {
            return $this->failure('Parâmetros de paginação inválidos.', 422);
        }

        $posts = new Post();
        // First page: pin the feed to the newest post that exists right now.
        $anchor = $anchor === 0 ? $posts->latestId() : $anchor;

        // The viewer is the session user (Auth::id()); "liked"/"reported" are computed for them only.
        $rows = $posts->feed((int) Auth::id(), $categoryId, $anchor, self::PAGE_SIZE + 1, $offset);
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        // ONE extra query for the latest 3 comments of every post on the page.
        $comments = (new PostComment())->latestForPosts(array_map(static fn (array $p): int => (int) $p['id'], $rows));

        return $this->success([
            'posts'       => array_map(fn (array $p): array => $this->presentPost($p, $comments[(int) $p['id']] ?? []), $rows),
            'has_more'    => $hasMore,
            'next_offset' => $offset + count($rows),
            'anchor'      => $anchor,
        ]);
    }

    /**
     * POST /api/community/posts  body: {"category": "pets", "body": "..."}
     *
     * Checks: CSRF (route middleware) → role → JSON well-formed (400) → rate
     * limit (429) → validation (422) → insert with the session's tenant and user.
     * 201 {"status":"success","data":{"post":{...}}}
     */
    public function store(): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.write', 10, 60)) {
            return $this->failure('Você está publicando rápido demais. Aguarde um minuto.', 429);
        }

        $errors = [];
        $category = (new SocialCategory())->findByCode($this->request->string('category'));
        if ($category === null) {
            $errors['category'] = 'Escolha uma categoria.';
        }
        $body = self::cleanText($this->request->string('body'));
        if ($body === '') {
            $errors['body'] = 'Escreva alguma coisa antes de publicar.';
        } elseif (mb_strlen($body) > Post::BODY_MAX) {
            $errors['body'] = 'A publicação pode ter até ' . Post::BODY_MAX . ' caracteres.';
        }
        if ($errors !== []) {
            return $this->failure('Verifique os campos destacados.', 422, $errors);
        }

        // author = session user; condominium = session tenant (set by TenantModel::insert()).
        $post = (new CommunityService())->createPost((int) Auth::id(), (int) $category['id'], $body);

        return $this->success(['post' => $this->presentPost($post)], 201);
    }
}
```

`app/Controllers/Community/LikeController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Response;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Likes, as two idempotent endpoints rather than one blind toggle:
 *
 *   POST   /api/community/posts/{id}/like   → liked
 *   DELETE /api/community/posts/{id}/like   → not liked
 *
 * The client says which state it wants, so a retried or duplicated request
 * cannot flip the like back. The database PRIMARY KEY (post, user) ensures
 * there is at most one like per person. Both answer
 *   200 {"status":"success","data":{"liked":bool,"likes_count":int}}
 * with the authoritative count, which the UI uses to reconcile its optimistic update.
 */
final class LikeController extends CommunityController
{
    /** POST /api/community/posts/{id}/like (CSRF via route middleware). */
    public function store(string $id): Response
    {
        return $this->setLike((int) $id, true);
    }

    /** DELETE /api/community/posts/{id}/like (CSRF via route middleware). */
    public function destroy(string $id): Response
    {
        return $this->setLike((int) $id, false);
    }

    private function setLike(int $postId, bool $like): Response
    {
        $this->requireRole(self::MEMBERS);

        try {
            // Tenant check (post in this condominium, published) happens inside the
            // service under a row lock; the actor is always the session user.
            $state = (new CommunityService())->setLike($postId, (int) Auth::id(), $like);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success($state);
    }
}
```

`app/Controllers/Community/CommentController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\PostComment;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Comments on posts.
 */
final class CommentController extends CommunityController
{
    /**
     * GET /api/community/posts/{id}/comments: every visible comment of a post.
     * 200 {"status":"success","data":{"comments":[...]}} / 404
     */
    public function index(string $id): Response
    {
        $this->requireRole(self::MEMBERS);

        try {
            $comments = (new CommunityService())->allComments((int) $id);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['comments' => array_map([$this, 'presentComment'], $comments)]);
    }

    /**
     * POST /api/community/posts/{id}/comments  body: {"body": "..."}
     *
     * CSRF (middleware) → role → 400 malformed JSON → 429 rate limit →
     * 422 validation → 404 if the post is not in this condominium (service) →
     * 201 {"status":"success","data":{"comment":{...},"comments_count":int}}
     */
    public function store(string $id): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.write', 10, 60)) {
            return $this->failure('Você está comentando rápido demais. Aguarde um minuto.', 429);
        }

        $body = self::cleanText($this->request->string('body'));
        if ($body === '' || mb_strlen($body) > PostComment::BODY_MAX) {
            $message = $body === ''
                ? 'O comentário não pode ficar vazio.'
                : 'O comentário pode ter até ' . PostComment::BODY_MAX . ' caracteres.';

            return $this->failure($message, 422, ['body' => $message]);
        }

        try {
            $result = (new CommunityService())->addComment((int) $id, (int) Auth::id(), $body);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success([
            'comment'        => $this->presentComment($result['comment']),
            'comments_count' => $result['comments_count'],
        ], 201);
    }
}
```

`app/Controllers/Community/ReportController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Response;
use App\Models\PostReport;
use App\Services\BusinessRuleException;
use App\Services\CommunityService;

/**
 * Reporting posts to the moderators.
 */
final class ReportController extends CommunityController
{
    /**
     * POST /api/community/posts/{id}/reports  body: {"reason": "spam", "details": "..."}
     *
     * 201 {"status":"success","data":{"reported":true}}
     * 409 already reported by this user (UNIQUE constraint) · 422 invalid reason / own post
     * 404 post not in this condominium
     */
    public function store(string $id): Response
    {
        $this->requireRole(self::MEMBERS);
        if ($error = $this->malformedBody()) {
            return $error;
        }
        if (!RateLimiter::attempt('community.report', 10, 600)) {
            return $this->failure('Muitas denúncias em pouco tempo. Tente novamente mais tarde.', 429);
        }

        // Allowlist: only the reasons of the schema's ENUM.
        $reason = $this->request->string('reason');
        if (!array_key_exists($reason, PostReport::REASONS)) {
            return $this->failure('Escolha um motivo.', 422, ['reason' => 'Escolha um motivo.']);
        }
        $details = self::cleanText($this->request->string('details'));
        if (mb_strlen($details) > 500) {
            return $this->failure('Detalhes: até 500 caracteres.', 422, ['details' => 'Até 500 caracteres.']);
        }

        try {
            (new CommunityService())->report((int) $id, (int) Auth::id(), $reason, $details === '' ? null : $details);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        // Whether the post was auto-hidden is not revealed to the reporter.
        return $this->success(['reported' => true], 201);
    }
}
```

`app/Controllers/Community/ModerationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Community;

use App\Core\Auth;
use App\Core\Response;
use App\Models\PostReport;
use App\Services\BusinessRuleException;
use App\Services\ModerationService;

/**
 * Moderation of reported posts.
 *
 * Server-side authorization on EVERY action. Each route carries a role:
 * middleware and each action repeats requireRole(), so no endpoint depends on
 * the UI hiding a button.
 */
final class ModerationController extends CommunityController
{
    /** GET /community/moderation: the queue (Super Admin can view; only managers can act). */
    public function index(): Response
    {
        $this->requireRole(self::MODERATION_VIEWERS);

        return $this->view('community/moderation', [
            'title'       => 'Moderação',
            'queue'       => (new ModerationService())->queue(),
            'reasons'     => PostReport::REASONS,
            'canModerate' => Auth::hasRole(self::MODERATORS),
            'isMember'    => Auth::hasRole(self::MEMBERS),
            'activeTab'   => 'moderation',
            'showModerationTab' => true,
            'scripts'     => ['js/community/moderation.js'],
        ], layout: 'layouts/community');
    }

    /**
     * DELETE /api/community/moderation/posts/{id}
     * 200 {"status":"success","data":{"deleted":true}} · 403 not a manager · 404 not in this condominium
     */
    public function destroy(string $id): Response
    {
        $this->requireRole(self::MODERATORS);

        try {
            (new ModerationService())->deletePost((int) $id, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['deleted' => true]);
    }

    /**
     * POST /api/community/moderation/posts/{id}/dismiss
     * 200 {"status":"success","data":{"dismissed":int}} · 403 · 404 · 409 nothing pending
     */
    public function dismiss(string $id): Response
    {
        $this->requireRole(self::MODERATORS);

        try {
            $count = (new ModerationService())->dismissReports((int) $id, (int) Auth::id(), $this->request);
        } catch (BusinessRuleException $e) {
            return $this->ruleFailure($e);
        }

        return $this->success(['dismissed' => $count]);
    }
}
```


### Routes

These were added to `routes/web.php`, with `use` statements for the six controllers:

```php
// … excerpt from routes/web.php
// =============================================================================
// Phase 4 - Community (social network)
//
// Same chain as Phase 3: auth → tenant → role → csrf. All /api/community/*
// endpoints answer JSON ({"status": "success"|"error", ...}); errors raised by
// middleware (401/403/419) use the same envelope via ErrorHandler.
// {id} is only a lookup key: services re-check that the post is in the session's tenant.
// =============================================================================

$members = $viewers(CommunityController::MEMBERS);
$moderators = $viewers(CommunityController::MODERATORS);

// Pages
$router->get('/community', [PostController::class, 'index'], ['auth', 'tenant', $members]);
$router->get('/community/moderation', [ModerationController::class, 'index'], [
    'auth', 'tenant', $viewers(CommunityController::MODERATION_VIEWERS),
]);

// Feed and posts
$router->get('/api/community/posts', [PostController::class, 'feed'], ['auth', 'tenant', $members]);
$router->post('/api/community/posts', [PostController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Likes (idempotent set / unset)
$router->post('/api/community/posts/{id:\d+}/like', [LikeController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);
$router->delete('/api/community/posts/{id:\d+}/like', [LikeController::class, 'destroy'], ['auth', 'tenant', $members, 'csrf']);

// Comments
$router->get('/api/community/posts/{id:\d+}/comments', [CommentController::class, 'index'], ['auth', 'tenant', $members]);
$router->post('/api/community/posts/{id:\d+}/comments', [CommentController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Reports
$router->post('/api/community/posts/{id:\d+}/reports', [ReportController::class, 'store'], ['auth', 'tenant', $members, 'csrf']);

// Moderation (managers only)
$router->delete('/api/community/moderation/posts/{id:\d+}', [ModerationController::class, 'destroy'], [
    'auth', 'tenant', $moderators, 'csrf',
]);
$router->post('/api/community/moderation/posts/{id:\d+}/dismiss', [ModerationController::class, 'dismiss'], [
    'auth', 'tenant', $moderators, 'csrf',
]);

return $router;
```


---

## 3. View and Vanilla JavaScript

### Layout and views

`app/Views/layouts/community.php`

```php
<?php
/**
 * Layout of the Community tab: friendly and card-based, deliberately different
 * from the corporate management layout (Phase 1, NFR-ARCH-07). Loads its own
 * stylesheet (community.css) instead of app.css.
 *
 * @var string                $content         Rendered page HTML (already escaped by the page template).
 * @var string                $title
 * @var list<string>          $scripts
 * @var string|null           $currentUserName
 * @var string|null           $tenantName
 * @var array<string, string> $flashes
 * @var string                $activeTab         "feed" | "moderation"
 * @var bool                  $showModerationTab Manager or Super Admin.
 * @var bool                  $isMember          False for the Super Admin (moderation view only).
 */
$isMember ??= true;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · Koinon</title>
    <link rel="stylesheet" href="<?= e(asset('css/community.css')) ?>">
</head>
<body class="community">
<header class="c-topbar">
    <div class="c-topbar__inner">
        <a class="c-brand" href="/community">
            <span class="c-brand__mark" aria-hidden="true">K</span>
            <span>Comunidade <span class="c-brand__tenant"><?= e($tenantName) ?></span></span>
        </a>

        <nav class="c-tabs" aria-label="Seções">
            <a class="c-tabs__item" href="/dashboard">Gestão</a>
            <?php if ($isMember): ?>
                <a class="c-tabs__item<?= $activeTab === 'feed' ? ' is-active' : '' ?>" href="/community">Mural</a>
            <?php endif; ?>
            <?php if ($showModerationTab): ?>
                <a class="c-tabs__item<?= $activeTab === 'moderation' ? ' is-active' : '' ?>" href="/community/moderation">Moderação</a>
            <?php endif; ?>
        </nav>

        <div class="c-me">
            <span class="c-me__name"><?= e($currentUserName) ?></span>
            <form method="post" action="/logout">
                <?= csrf_field() ?>
                <button type="submit" class="c-btn c-btn--ghost">Sair</button>
            </form>
        </div>
    </div>
</header>

<main class="c-main">
    <?php foreach ($flashes as $type => $message): ?>
        <div class="c-alert c-alert--<?= e($type) ?>" role="status"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?= $content /* already-escaped page HTML */ ?>
</main>

<!-- Toasts are created by community/toast.js with textContent only. -->
<div class="c-toasts" id="toast-region" aria-live="polite" aria-atomic="false"></div>

<?php foreach ($scripts as $script): ?>
    <script type="module" src="<?= e(asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
```

`app/Views/community/index.php`

```php
<?php
/**
 * Community feed page.
 *
 * Post cards are NOT rendered here: community/feed.js loads them from
 * GET /api/community/posts and builds each card by cloning #post-card-template
 * and filling it with textContent. The template holds only static markup, so
 * the card structure is defined once (here) and user content never touches innerHTML.
 *
 * @var list<array{id: int, code: string, name: string}> $categories Allowlist from social_categories.
 * @var array<string, string> $reasons    Report reasons (PostReport::REASONS).
 * @var int                   $bodyMax
 * @var int                   $commentMax
 * @var string|null           $currentUserName
 * @var bool                  $canModerate
 */
?>
<div class="c-grid">
    <section class="c-column">
        <!-- Composer -->
        <form class="c-card c-composer" id="post-composer" novalidate>
            <div class="c-composer__row">
                <span class="c-avatar" data-initials-of="<?= e($currentUserName) ?>" aria-hidden="true"></span>
                <label class="c-sr-only" for="composer-body">Escreva uma publicação</label>
                <textarea id="composer-body" name="body" rows="3" maxlength="<?= e($bodyMax) ?>"
                          placeholder="Compartilhe algo com seus vizinhos…" required></textarea>
            </div>
            <div class="c-composer__footer">
                <label class="c-select">
                    <span class="c-sr-only">Categoria</span>
                    <select name="category" required>
                        <option value="">Categoria…</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= e($category['code']) ?>"><?= e($category['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <span class="c-counter" data-char-counter data-max="<?= e($bodyMax) ?>">0 / <?= e($bodyMax) ?></span>
                <button type="submit" class="c-btn c-btn--primary">Publicar</button>
            </div>
        </form>

        <!-- Category filter -->
        <nav class="c-chips" id="category-filter" aria-label="Filtrar por categoria">
            <button type="button" class="c-chip is-active" data-category="" aria-pressed="true">Tudo</button>
            <?php foreach ($categories as $category): ?>
                <button type="button" class="c-chip c-chip--<?= e($category['code']) ?>"
                        data-category="<?= e($category['code']) ?>" aria-pressed="false"><?= e($category['name']) ?></button>
            <?php endforeach; ?>
        </nav>

        <!-- Feed (event delegation root) -->
        <section id="feed" class="c-feed" aria-live="polite" aria-busy="true"
                 data-comment-max="<?= e($commentMax) ?>">
            <div class="c-state" data-state="loading">
                <div class="c-skeleton"></div>
                <div class="c-skeleton"></div>
            </div>
            <div class="c-state c-state--empty" data-state="empty" hidden>
                <p class="c-state__title">Nada por aqui ainda</p>
                <p>Seja o primeiro a publicar nesta categoria!</p>
            </div>
            <div class="c-state c-state--error" data-state="error" hidden>
                <p class="c-state__title">Não foi possível carregar o mural.</p>
                <button type="button" class="c-btn" data-action="retry">Tentar novamente</button>
            </div>
            <div class="c-feed__list" data-feed-list></div>
        </section>

        <div class="c-more">
            <button type="button" class="c-btn c-btn--soft" id="load-more" hidden>Carregar mais publicações</button>
        </div>
    </section>

    <aside class="c-aside">
        <div class="c-card c-card--padded">
            <h2 class="c-aside__title">Boas-vindas à comunidade 👋</h2>
            <p>Um espaço para vizinhos se ajudarem: vendas, achados e perdidos, dicas e pets.</p>
            <ul class="c-rules">
                <li>Seja gentil e respeitoso.</li>
                <li>Nada de dados pessoais de terceiros.</li>
                <li>Viu algo inadequado? Use “Denunciar”.</li>
            </ul>
            <?php if ($canModerate): ?>
                <a class="c-btn c-btn--soft c-btn--block" href="/community/moderation">Abrir moderação</a>
            <?php endif; ?>
        </div>
    </aside>
</div>

<!-- Post card template: static markup only; filled by community/render.js -->
<template id="post-card-template">
    <article class="c-card c-post">
        <header class="c-post__header">
            <span class="c-avatar" data-field="avatar" aria-hidden="true"></span>
            <div class="c-post__meta">
                <strong class="c-post__author" data-field="author"></strong>
                <time class="c-post__time" data-field="time"></time>
            </div>
            <span class="c-badge" data-field="category"></span>
        </header>

        <p class="c-post__body" data-field="body"></p>

        <div class="c-post__actions">
            <button type="button" class="c-action c-action--like" data-action="like" aria-pressed="false">
                <span class="c-action__icon" aria-hidden="true">♥</span>
                <span data-field="likes-label">Curtir</span>
                <span class="c-action__count" data-field="likes-count">0</span>
            </button>
            <button type="button" class="c-action" data-action="focus-comment">
                <span class="c-action__icon" aria-hidden="true">💬</span>
                Comentar
                <span class="c-action__count" data-field="comments-count">0</span>
            </button>
            <button type="button" class="c-action c-action--report" data-action="report">
                <span class="c-action__icon" aria-hidden="true">⚑</span>
                <span data-field="report-label">Denunciar</span>
            </button>
        </div>

        <div class="c-comments">
            <button type="button" class="c-link" data-action="load-comments" hidden></button>
            <ul class="c-comments__list" data-field="comments"></ul>
            <form class="c-comment-form" data-comment-form novalidate>
                <label class="c-sr-only">Escreva um comentário
                    <input type="text" name="body" required autocomplete="off">
                </label>
                <button type="submit" class="c-btn c-btn--small c-btn--primary">Enviar</button>
            </form>
        </div>
    </article>
</template>

<!-- One report dialog, reused for every post (data-post-id set by feed.js) -->
<dialog class="c-dialog" id="report-dialog" aria-labelledby="report-title">
    <form method="dialog" class="c-dialog__form" id="report-form" novalidate>
        <h2 id="report-title" class="c-dialog__title">Denunciar publicação</h2>
        <p class="c-muted">A administração vai analisar. Quem publicou não verá quem denunciou.</p>
        <fieldset class="c-reasons">
            <legend class="c-sr-only">Motivo</legend>
            <?php foreach ($reasons as $value => $label): ?>
                <label class="c-reason">
                    <input type="radio" name="reason" value="<?= e($value) ?>" required>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <label class="c-field">
            <span>Detalhes (opcional)</span>
            <textarea name="details" rows="3" maxlength="500"></textarea>
        </label>
        <div class="c-dialog__actions">
            <button type="button" class="c-btn" data-close-dialog>Cancelar</button>
            <button type="submit" class="c-btn c-btn--danger" value="send">Enviar denúncia</button>
        </div>
    </form>
</dialog>
```

`app/Views/community/moderation.php`

```php
<?php
/**
 * Moderation queue, rendered on the server: every piece of user content is
 * printed with e(). Actions (delete / dismiss) are sent by
 * community/moderation.js and enforced server-side (role "manager").
 *
 * Reporters are not identified here on purpose (data minimisation).
 *
 * @var list<array<string, mixed>> $queue    Posts with pending reports, each with 'reports'.
 * @var array<string, string>      $reasons
 * @var bool                       $canModerate False for the Super Admin (view only).
 */
?>
<div class="c-page-head">
    <div>
        <h1 class="c-page-title">Moderação</h1>
        <p class="c-muted">Publicações denunciadas pelos moradores. Publicações com 3 ou mais denúncias ficam ocultas até a sua análise.</p>
    </div>
    <span class="c-pill" data-queue-count><?= e(count($queue)) ?> pendente(s)</span>
</div>

<?php if (!$canModerate): ?>
    <div class="c-alert c-alert--warning">Modo de consulta: apenas a administração do condomínio pode remover publicações ou descartar denúncias.</div>
<?php endif; ?>

<section class="c-modlist" id="moderation-list">
    <?php if ($queue === []): ?>
        <div class="c-card c-state c-state--empty">
            <p class="c-state__title">Tudo tranquilo por aqui ✨</p>
            <p>Nenhuma denúncia pendente.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($queue as $post): ?>
        <article class="c-card c-mod" data-post-id="<?= e($post['id']) ?>">
            <header class="c-post__header">
                <span class="c-avatar" data-initials-of="<?= e($post['author_name']) ?>" aria-hidden="true"></span>
                <div class="c-post__meta">
                    <strong class="c-post__author"><?= e($post['author_name']) ?></strong>
                    <span class="c-post__time"><?= e(local_datetime($post['created_at'])) ?></span>
                </div>
                <span class="c-badge c-badge--<?= e($post['category_code']) ?>"><?= e($post['category_name']) ?></span>
                <?php if ($post['status'] === 'hidden'): ?>
                    <span class="c-pill c-pill--warning">Oculta automaticamente</span>
                <?php endif; ?>
            </header>

            <p class="c-post__body"><?= e($post['body']) ?></p>

            <div class="c-mod__reports">
                <strong><?= e($post['pending_reports']) ?> denúncia(s)</strong>
                <ul>
                    <?php foreach ($post['reports'] as $report): ?>
                        <li>
                            <span class="c-pill c-pill--danger"><?= e($reasons[$report['reason']] ?? $report['reason']) ?></span>
                            <?php if (!empty($report['details'])): ?>
                                <span class="c-mod__details">“<?= e($report['details']) ?>”</span>
                            <?php endif; ?>
                            <span class="c-muted"><?= e(local_datetime($report['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ($canModerate): ?>
                <div class="c-mod__actions">
                    <button type="button" class="c-btn" data-action="dismiss">Descartar denúncias</button>
                    <button type="button" class="c-btn c-btn--danger" data-action="delete">Excluir publicação</button>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
```


### JavaScript modules

```
public/assets/js/
├── core/http.js            fetch wrapper: CSRF header, timeout, HttpError (shared with Phase 2/3)
└── community/
    ├── api.js              one function per endpoint + errorMessage()
    ├── toast.js            toasts (textContent only)
    ├── render.js           data → DOM (template clone + textContent/createElement)
    ├── feed.js             state + delegated event handlers on #feed
    └── moderation.js       delete / dismiss in the moderation view
```

**Optimistic like** (`feed.js` → `toggleLike`):

1. The button and counter change immediately.
2. `POST` or `DELETE …/like` is sent.
3. The UI is reconciled with the server's `{liked, likes_count}`.
4. On any error the previous state is restored and a toast explains why. On a 404, the card is removed because the post was deleted meanwhile.

The button stays disabled while the request is in flight, and a click on a disabled button is ignored *before* the optimistic change, so the UI never flips without a request behind it.

`public/assets/js/core/http.js`

```javascript
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
     * @param {any} payload Decoded JSON body ({status, message, errors} or the older {error, errors}), or null.
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
export const deleteJson = (url) => request('DELETE', url);
```

`public/assets/js/community/api.js`

```javascript
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
```

`public/assets/js/community/toast.js`

```javascript
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
```

`public/assets/js/community/render.js`

```javascript
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
```

`public/assets/js/community/feed.js`

```javascript
/**
 * Community feed: event handlers and state.
 *
 * One delegated listener per event type on the #feed container handles every
 * card, including cards added later by "Load more" or by publishing, so no
 * per-card listeners need to be attached or cleaned up.
 */
import * as api from './api.js';
import { renderPost, renderComment, setLikeState, setCommentsCount, setReported, fillAvatar } from './render.js';
import { showToast } from './toast.js';

const feed = document.getElementById('feed');
const list = feed.querySelector('[data-feed-list]');
const template = document.getElementById('post-card-template');
const loadMoreButton = document.getElementById('load-more');
const composer = document.getElementById('post-composer');
const filter = document.getElementById('category-filter');
const reportDialog = document.getElementById('report-dialog');
const reportForm = document.getElementById('report-form');

/** Paging state. anchor pins the feed to the posts that existed on first load. */
const state = { category: '', offset: 0, anchor: 0, hasMore: false, loading: false };

// ---------------------------------------------------------------- helpers

function showState(name) {
    feed.querySelectorAll('[data-state]').forEach((el) => {
        el.hidden = el.dataset.state !== name;
    });
    feed.setAttribute('aria-busy', String(name === 'loading'));
}

const cardOf = (element) => element.closest('[data-post-id]');

/** Runs fn with the button disabled, so a double click cannot send twice. */
async function withBusy(button, fn) {
    if (button.disabled) {
        return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        await fn();
    } finally {
        button.removeAttribute('aria-busy');
        if (button.isConnected && !button.classList.contains('is-done')) {
            button.disabled = false;
        }
    }
}

// ---------------------------------------------------------------- loading pages

async function loadPage({ reset = false } = {}) {
    if (state.loading) {
        return;
    }
    state.loading = true;
    if (reset) {
        state.offset = 0;
        state.anchor = 0;
        list.replaceChildren();
        showState('loading');
    }
    loadMoreButton.disabled = true;

    try {
        const data = await api.listPosts({ category: state.category, offset: state.offset, anchor: state.anchor });
        // Skip cards already on screen (e.g. a post the user just published).
        const fresh = data.posts.filter((post) => !list.querySelector(`[data-post-id="${post.id}"]`));
        list.append(...fresh.map((post) => renderPost(template, post)));

        state.offset = data.next_offset;
        state.anchor = data.anchor;
        state.hasMore = data.has_more;
        showState(list.children.length === 0 ? 'empty' : null);
    } catch (error) {
        if (reset) {
            showState('error');
        } else {
            showToast(api.errorMessage(error), 'error');
        }
    } finally {
        state.loading = false;
        loadMoreButton.disabled = false;
        loadMoreButton.hidden = !state.hasMore;
    }
}

// ---------------------------------------------------------------- likes (optimistic)

async function toggleLike(button) {
    if (button.disabled) {
        return; // a request is in flight: do not flip the UI without sending one
    }
    const card = cardOf(button);
    const wasLiked = card.dataset.liked === 'true';
    const previousCount = Number(card.dataset.likesCount);
    const wantLiked = !wasLiked;

    // 1. Optimistic: the UI changes at once.
    setLikeState(card, wantLiked, previousCount + (wantLiked ? 1 : -1));

    await withBusy(button, async () => {
        try {
            // 2. Reconcile with the authoritative state and count from the server.
            const result = await api.setLike(card.dataset.postId, wantLiked);
            setLikeState(card, result.liked, result.likes_count);
        } catch (error) {
            // 3. Roll back on any failure.
            setLikeState(card, wasLiked, previousCount);
            showToast(api.errorMessage(error), 'error');
            if (error instanceof api.HttpError && error.status === 404) {
                card.remove(); // removed by a moderator meanwhile
            }
        }
    });
}

// ---------------------------------------------------------------- comments

async function submitComment(form) {
    const card = cardOf(form);
    const input = form.elements.namedItem('body');
    const button = form.querySelector('[type="submit"]');
    const body = input.value.trim();

    if (body === '') {
        showToast('Escreva algo antes de enviar.', 'info');
        input.focus();
        return;
    }

    await withBusy(button, async () => {
        input.disabled = true;
        try {
            const result = await api.addComment(card.dataset.postId, body);
            card.querySelector('[data-field="comments"]').append(renderComment(result.comment));
            setCommentsCount(card, result.comments_count);
            input.value = '';
        } catch (error) {
            showToast(api.errorMessage(error), 'error'); // the text stays in the input for a retry
        } finally {
            input.disabled = false;
            input.focus();
        }
    });
}

async function loadAllComments(button) {
    const card = cardOf(button);
    await withBusy(button, async () => {
        try {
            const result = await api.listComments(card.dataset.postId);
            card.querySelector('[data-field="comments"]').replaceChildren(...result.comments.map(renderComment));
            setCommentsCount(card, Number(card.dataset.commentsCount));
        } catch (error) {
            showToast(api.errorMessage(error), 'error');
        }
    });
}

// ---------------------------------------------------------------- reports

function openReportDialog(button) {
    reportForm.reset();
    reportDialog.dataset.postId = cardOf(button).dataset.postId;
    reportDialog.showModal();
}

async function submitReport(event) {
    event.preventDefault();
    const reason = reportForm.elements.namedItem('reason').value;
    if (!reason) {
        showToast('Escolha um motivo para a denúncia.', 'info');
        return;
    }
    const details = reportForm.elements.namedItem('details').value.trim();
    const postId = reportDialog.dataset.postId;
    const card = list.querySelector(`[data-post-id="${CSS.escape(postId)}"]`);
    const sendButton = reportForm.querySelector('[type="submit"]');

    await withBusy(sendButton, async () => {
        try {
            await api.reportPost(postId, reason, details);
            showToast('Denúncia enviada. Obrigado por ajudar a manter a comunidade saudável!', 'success');
            if (card) setReported(card);
            reportDialog.close();
        } catch (error) {
            if (error instanceof api.HttpError && error.status === 409) {
                showToast('Você já denunciou esta publicação. A administração vai analisar.', 'info');
                if (card) setReported(card);
                reportDialog.close();
            } else {
                showToast(api.errorMessage(error), 'error');
            }
        }
    });
}

// ---------------------------------------------------------------- composer

const counter = composer.querySelector('[data-char-counter]');
const updateCounter = () => {
    const length = composer.elements.namedItem('body').value.length;
    counter.textContent = `${length} / ${counter.dataset.max}`;
    counter.classList.toggle('is-near-limit', length > Number(counter.dataset.max) * 0.9);
};

async function submitPost(event) {
    event.preventDefault();
    const textarea = composer.elements.namedItem('body');
    const select = composer.elements.namedItem('category');
    const body = textarea.value.trim();

    if (body === '') {
        showToast('Escreva algo antes de publicar.', 'info');
        textarea.focus();
        return;
    }
    if (select.value === '') {
        showToast('Escolha uma categoria.', 'info');
        select.focus();
        return;
    }

    await withBusy(composer.querySelector('[type="submit"]'), async () => {
        try {
            const { post } = await api.createPost(select.value, body);
            composer.reset();
            updateCounter();
            if (state.category === '' || state.category === post.category.code) {
                list.prepend(renderPost(template, post));
                showState(null);
                showToast('Publicado!', 'success');
            } else {
                showToast(`Publicado em “${post.category.name}”.`, 'success');
            }
        } catch (error) {
            showToast(api.errorMessage(error), 'error');
        }
    });
}

// ---------------------------------------------------------------- wiring (event delegation)

feed.addEventListener('click', (event) => {
    const button = event.target.closest('[data-action]');
    if (!button || !feed.contains(button)) {
        return;
    }
    switch (button.dataset.action) {
        case 'like':
            toggleLike(button);
            break;
        case 'focus-comment':
            cardOf(button).querySelector('[data-comment-form] input[name="body"]').focus();
            break;
        case 'report':
            openReportDialog(button);
            break;
        case 'load-comments':
            loadAllComments(button);
            break;
        case 'retry':
            loadPage({ reset: true });
            break;
        default:
            break;
    }
});

feed.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-comment-form]');
    if (form) {
        event.preventDefault();
        submitComment(form);
    }
});

filter.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-category]');
    if (!chip || state.loading) {
        return;
    }
    filter.querySelectorAll('[data-category]').forEach((c) => {
        c.classList.toggle('is-active', c === chip);
        c.setAttribute('aria-pressed', String(c === chip));
    });
    state.category = chip.dataset.category;
    loadPage({ reset: true });
});

composer.addEventListener('submit', submitPost);
composer.elements.namedItem('body').addEventListener('input', updateCounter);
loadMoreButton.addEventListener('click', () => loadPage());
reportForm.addEventListener('submit', submitReport);
reportForm.querySelector('[data-close-dialog]').addEventListener('click', () => reportDialog.close());

document.querySelectorAll('[data-initials-of]').forEach((el) => fillAvatar(el, el.dataset.initialsOf));

loadPage({ reset: true });
```

`public/assets/js/community/moderation.js`

```javascript
/**
 * Moderation view: delete a reported post or dismiss its reports, without
 * reloading. The page is server-rendered; this script only sends the actions.
 * The server re-checks the moderator role and the tenant on every request.
 */
import * as api from './api.js';
import { fillAvatar } from './render.js';
import { showToast } from './toast.js';

const listElement = document.getElementById('moderation-list');
const counter = document.querySelector('[data-queue-count]');

const ACTIONS = {
    delete: {
        confirm: 'Excluir esta publicação? Curtidas, comentários e denúncias também serão apagados. Esta ação não pode ser desfeita.',
        run: (id) => api.deletePostAsModerator(id),
        done: 'Publicação excluída.',
    },
    dismiss: {
        confirm: 'Descartar as denúncias? A publicação volta (ou continua) visível no mural.',
        run: (id) => api.dismissReports(id),
        done: 'Denúncias descartadas.',
    },
};

function removeCard(card) {
    card.classList.add('is-leaving');
    card.addEventListener('transitionend', () => card.remove(), { once: true });
    setTimeout(() => card.remove(), 400);
    const remaining = listElement.querySelectorAll('[data-post-id]:not(.is-leaving)').length;
    counter.textContent = `${remaining} pendente(s)`;
}

listElement?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-action]');
    const action = button ? ACTIONS[button.dataset.action] : null;
    if (!action || button.disabled) {
        return;
    }
    const card = button.closest('[data-post-id]');
    if (!window.confirm(action.confirm)) {
        return;
    }

    const buttons = card.querySelectorAll('button');
    buttons.forEach((b) => { b.disabled = true; });
    try {
        await action.run(card.dataset.postId);
        showToast(action.done, 'success');
        removeCard(card);
    } catch (error) {
        showToast(api.errorMessage(error), 'error');
        if (error instanceof api.HttpError && error.status === 404) {
            removeCard(card); // already handled by another moderator
            return;
        }
        buttons.forEach((b) => { b.disabled = false; });
    }
});

document.querySelectorAll('[data-initials-of]').forEach((el) => fillAvatar(el, el.dataset.initialsOf));
```


### CSS

`public/assets/css/community.css`

```css
/*
 * Koinon - Community tab (social network).
 * Friendly and modern on purpose: soft background, rounded cards, warm accents,
 * coloured category badges. Separate from app.css (corporate management area).
 * No inline styles anywhere: the Content-Security-Policy only allows this file.
 */

:root {
    --c-bg: #f5f3fb;
    --c-surface: #ffffff;
    --c-border: #e7e3f3;
    --c-text: #24213a;
    --c-muted: #6e6a86;
    --c-primary: #6c4fe0;
    --c-primary-hover: #5a3fcb;
    --c-primary-soft: #efeafe;
    --c-like: #ec4f6a;
    --c-like-soft: #fde9ee;
    --c-danger: #d64545;
    --c-danger-soft: #fdecec;
    --c-warning: #b7791f;
    --c-warning-soft: #fdf3e1;
    --c-success: #2f9e6b;

    --c-radius: 18px;
    --c-radius-small: 12px;
    --c-shadow: 0 2px 10px rgba(52, 38, 120, 0.06);
    --c-shadow-hover: 0 6px 22px rgba(52, 38, 120, 0.1);
    --c-font: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
}

body.community {
    min-width: 1024px;
    min-height: 100vh;
    font-family: var(--c-font);
    font-size: 15px;
    line-height: 1.55;
    color: var(--c-text);
    background: var(--c-bg);
}

[hidden] {
    display: none !important;
}

a {
    color: var(--c-primary);
}

.c-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.c-muted {
    color: var(--c-muted);
}

/* ---------- Top bar ---------- */

.c-topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid var(--c-border);
}

.c-topbar__inner {
    max-width: 1100px;
    margin: 0 auto;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 24px;
}

.c-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    color: var(--c-text);
    text-decoration: none;
}

.c-brand__mark {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 11px;
    color: #ffffff;
    background: linear-gradient(135deg, var(--c-primary), #a07cf5);
}

.c-brand__tenant {
    font-weight: 400;
    color: var(--c-muted);
}

.c-tabs {
    display: flex;
    gap: 4px;
    flex: 1;
}

.c-tabs__item {
    padding: 8px 14px;
    border-radius: 999px;
    color: var(--c-muted);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
}

.c-tabs__item:hover {
    background: var(--c-bg);
}

.c-tabs__item.is-active {
    background: var(--c-primary-soft);
    color: var(--c-primary);
}

.c-me {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
}

.c-me form {
    margin: 0;
}

/* ---------- Layout ---------- */

.c-main {
    max-width: 1100px;
    margin: 0 auto;
    padding: 28px 24px 64px;
}

.c-grid {
    display: grid;
    grid-template-columns: minmax(0, 660px) 300px;
    gap: 28px;
    justify-content: center;
    align-items: start;
}

.c-column {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.c-aside {
    position: sticky;
    top: 84px;
}

.c-aside__title {
    margin: 0 0 8px;
    font-size: 17px;
}

.c-rules {
    margin: 12px 0 16px;
    padding-left: 18px;
    color: var(--c-muted);
}

.c-card {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--c-radius);
    box-shadow: var(--c-shadow);
}

.c-card--padded {
    padding: 20px;
}

/* ---------- Buttons ---------- */

.c-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 9px 18px;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    color: var(--c-text);
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: 999px;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, transform 0.1s, box-shadow 0.15s;
}

.c-btn:hover {
    background: var(--c-bg);
}

.c-btn:active {
    transform: scale(0.97);
}

.c-btn:disabled {
    opacity: 0.6;
    cursor: progress;
    transform: none;
}

.c-btn--primary {
    color: #ffffff;
    background: var(--c-primary);
    border-color: var(--c-primary);
    box-shadow: 0 4px 12px rgba(108, 79, 224, 0.25);
}

.c-btn--primary:hover {
    background: var(--c-primary-hover);
}

.c-btn--soft {
    color: var(--c-primary);
    background: var(--c-primary-soft);
    border-color: transparent;
}

.c-btn--soft:hover {
    background: #e3dafd;
}

.c-btn--danger {
    color: #ffffff;
    background: var(--c-danger);
    border-color: var(--c-danger);
}

.c-btn--danger:hover {
    background: #bd3636;
}

.c-btn--ghost {
    background: transparent;
}

.c-btn--small {
    padding: 6px 14px;
    font-size: 13px;
}

.c-btn--block {
    width: 100%;
}

.c-btn:focus-visible,
.c-chip:focus-visible,
.c-action:focus-visible,
.c-link:focus-visible,
textarea:focus-visible,
input:focus-visible,
select:focus-visible {
    outline: 3px solid rgba(108, 79, 224, 0.35);
    outline-offset: 2px;
}

/* ---------- Avatars ---------- */

.c-avatar {
    flex-shrink: 0;
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
    background: var(--c-primary);
}

.c-avatar--small {
    width: 30px;
    height: 30px;
    font-size: 12px;
}

.c-avatar--c0 { background: #6c4fe0; }
.c-avatar--c1 { background: #ec4f6a; }
.c-avatar--c2 { background: #2f9e6b; }
.c-avatar--c3 { background: #e08a1e; }
.c-avatar--c4 { background: #2b8fd6; }
.c-avatar--c5 { background: #b04fc9; }

/* ---------- Composer ---------- */

.c-composer {
    padding: 18px;
}

.c-composer__row {
    display: flex;
    gap: 12px;
}

.c-composer textarea {
    flex: 1;
    min-height: 74px;
    padding: 10px 14px;
    font: inherit;
    color: var(--c-text);
    background: var(--c-bg);
    border: 1px solid transparent;
    border-radius: var(--c-radius-small);
    resize: vertical;
}

.c-composer textarea:focus {
    background: #ffffff;
    border-color: var(--c-border);
}

.c-composer__footer {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    padding-left: 54px;
}

.c-select select {
    padding: 8px 12px;
    font: inherit;
    font-size: 14px;
    color: var(--c-text);
    background: var(--c-bg);
    border: 1px solid var(--c-border);
    border-radius: 999px;
}

.c-counter {
    flex: 1;
    font-size: 12px;
    color: var(--c-muted);
    text-align: right;
}

.c-counter.is-near-limit {
    color: var(--c-danger);
}

/* ---------- Category chips and badges ---------- */

.c-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.c-chip {
    padding: 7px 16px;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    color: var(--c-muted);
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: 999px;
    cursor: pointer;
}

.c-chip:hover {
    color: var(--c-text);
}

.c-chip.is-active {
    color: #ffffff;
    background: var(--c-text);
    border-color: var(--c-text);
}

.c-badge {
    margin-left: auto;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    color: var(--c-muted);
    background: var(--c-bg);
    white-space: nowrap;
}

.c-badge--classifieds { color: #9a5b00; background: #fff1d6; }
.c-badge--lost_found { color: #1f6fb2; background: #e2f0fd; }
.c-badge--neighborhood_tips { color: #22794f; background: #def5e9; }
.c-badge--pets { color: #b5346d; background: #fde4ef; }

/* ---------- Feed and post cards ---------- */

.c-feed__list {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.c-post {
    padding: 18px 20px 14px;
    transition: box-shadow 0.2s;
    animation: c-enter 0.25s ease-out;
}

.c-post:hover {
    box-shadow: var(--c-shadow-hover);
}

@keyframes c-enter {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
}

.c-post__header {
    display: flex;
    align-items: center;
    gap: 12px;
}

.c-post__meta {
    display: flex;
    flex-direction: column;
    line-height: 1.3;
}

.c-post__time {
    font-size: 13px;
    color: var(--c-muted);
}

.c-post__body {
    margin: 14px 0;
    /* Line breaks typed by the author are kept without ever turning text into HTML. */
    white-space: pre-line;
    overflow-wrap: anywhere;
    font-size: 15.5px;
}

.c-post__actions {
    display: flex;
    gap: 6px;
    padding-top: 10px;
    border-top: 1px solid var(--c-border);
}

.c-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    color: var(--c-muted);
    background: transparent;
    border: 0;
    border-radius: 999px;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.c-action:hover {
    background: var(--c-bg);
    color: var(--c-text);
}

.c-action__count {
    min-width: 1ch;
    font-variant-numeric: tabular-nums;
}

.c-action--like.is-active {
    color: var(--c-like);
    background: var(--c-like-soft);
}

.c-action--like.is-active .c-action__icon {
    animation: c-pop 0.3s ease-out;
}

@keyframes c-pop {
    50% {
        transform: scale(1.35);
    }
}

.c-action--report {
    margin-left: auto;
}

.c-action--report.is-done {
    cursor: default;
    color: var(--c-warning);
}

.c-action[aria-busy="true"] {
    opacity: 0.7;
}

/* ---------- Comments ---------- */

.c-comments {
    margin-top: 10px;
}

.c-link {
    padding: 0;
    margin: 4px 0 8px;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    color: var(--c-muted);
    background: none;
    border: 0;
    cursor: pointer;
}

.c-link:hover {
    color: var(--c-primary);
}

.c-comments__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.c-comment {
    display: flex;
    gap: 8px;
    animation: c-enter 0.2s ease-out;
}

.c-comment__bubble {
    padding: 8px 12px;
    background: var(--c-bg);
    border-radius: 14px;
    font-size: 14px;
}

.c-comment__bubble p {
    margin: 2px 0;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.c-comment__bubble time {
    font-size: 12px;
    color: var(--c-muted);
}

.c-comment-form {
    display: flex;
    gap: 8px;
    margin-top: 10px;
}

.c-comment-form label {
    flex: 1;
    display: flex;
}

.c-comment-form input {
    flex: 1;
    padding: 8px 14px;
    font: inherit;
    font-size: 14px;
    color: var(--c-text);
    background: var(--c-bg);
    border: 1px solid transparent;
    border-radius: 999px;
}

.c-comment-form input:focus {
    background: #ffffff;
    border-color: var(--c-border);
}

/* ---------- States, skeleton, load more ---------- */

.c-state {
    padding: 32px 20px;
    text-align: center;
    color: var(--c-muted);
}

.c-state__title {
    margin: 0 0 4px;
    font-weight: 700;
    color: var(--c-text);
}

.c-state--error .c-state__title {
    color: var(--c-danger);
}

.c-state[data-state="loading"] {
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.c-skeleton {
    height: 150px;
    border-radius: var(--c-radius);
    background: linear-gradient(90deg, #ece8f7 25%, #f6f3fd 50%, #ece8f7 75%);
    background-size: 200% 100%;
    animation: c-shimmer 1.2s infinite linear;
}

@keyframes c-shimmer {
    to {
        background-position: -200% 0;
    }
}

.c-more {
    display: flex;
    justify-content: center;
}

/* ---------- Report dialog ---------- */

.c-dialog {
    width: 460px;
    max-width: calc(100vw - 32px);
    padding: 0;
    border: 0;
    border-radius: var(--c-radius);
    box-shadow: 0 20px 50px rgba(36, 33, 58, 0.25);
}

.c-dialog::backdrop {
    background: rgba(36, 33, 58, 0.4);
}

.c-dialog__form {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.c-dialog__title {
    margin: 0;
    font-size: 19px;
}

.c-dialog__form p {
    margin: 0;
}

.c-reasons {
    margin: 0;
    padding: 0;
    border: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.c-reason {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 12px;
    border: 1px solid var(--c-border);
    border-radius: var(--c-radius-small);
    cursor: pointer;
}

.c-reason:has(input:checked) {
    border-color: var(--c-primary);
    background: var(--c-primary-soft);
}

.c-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 14px;
    font-weight: 600;
}

.c-field textarea {
    padding: 10px 12px;
    font: inherit;
    font-weight: 400;
    border: 1px solid var(--c-border);
    border-radius: var(--c-radius-small);
    resize: vertical;
}

.c-dialog__actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* ---------- Toasts and alerts ---------- */

.c-toasts {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 50;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}

.c-toast {
    max-width: 380px;
    padding: 12px 18px;
    border-radius: 14px;
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
    background: var(--c-text);
    box-shadow: 0 10px 28px rgba(36, 33, 58, 0.25);
    opacity: 0;
    transform: translateY(10px);
    transition: opacity 0.2s, transform 0.2s;
}

.c-toast.is-visible {
    opacity: 1;
    transform: none;
}

.c-toast--success { background: var(--c-success); }
.c-toast--error { background: var(--c-danger); }

.c-alert {
    padding: 12px 16px;
    margin-bottom: 18px;
    border-radius: var(--c-radius-small);
    font-size: 14px;
    background: var(--c-primary-soft);
}

.c-alert--warning {
    background: var(--c-warning-soft);
    color: var(--c-warning);
}

.c-alert--error {
    background: var(--c-danger-soft);
    color: var(--c-danger);
}

/* ---------- Moderation ---------- */

.c-page-head {
    max-width: 820px;
    margin: 0 auto 20px;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.c-page-title {
    margin: 0 0 4px;
    font-size: 26px;
}

.c-page-head p {
    margin: 0;
}

.c-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    color: var(--c-primary);
    background: var(--c-primary-soft);
}

.c-pill--warning {
    color: var(--c-warning);
    background: var(--c-warning-soft);
}

.c-pill--danger {
    color: var(--c-danger);
    background: var(--c-danger-soft);
}

.c-modlist {
    max-width: 820px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.c-mod {
    padding: 18px 20px;
    transition: opacity 0.3s, transform 0.3s;
}

.c-mod.is-leaving {
    opacity: 0;
    transform: translateX(20px);
}

.c-mod .c-badge {
    margin-left: auto;
}

.c-mod__reports {
    padding: 12px 14px;
    border-radius: var(--c-radius-small);
    background: var(--c-danger-soft);
    font-size: 14px;
}

.c-mod__reports ul {
    list-style: none;
    margin: 8px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.c-mod__reports li {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.c-mod__details {
    font-style: italic;
}

.c-mod__actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 14px;
}
```


---

## Schema changes

**None are required.** The Phase 1 schema already enforces every constraint this phase relies on:

| Requirement | Already in `database/schema.sql` |
|-------------|----------------------------------|
| One like per user per post | `post_likes` `PRIMARY KEY (condominium_id, post_id, user_id)` |
| One report per user per post | `post_reports` `UNIQUE KEY uq_post_reports_once (condominium_id, post_id, reporter_user_id)` |
| Deleting a post removes likes, comments, images and reports | `ON DELETE CASCADE` on `fk_post_likes_post`, `fk_post_comments_post`, `fk_post_images_post` and `fk_post_reports_post` |
| Likes, comments and reports belong to the post's condominium | Composite FKs `(condominium_id, post_id) → posts (condominium_id, id)` |
| Actors are members of that condominium | Composite FKs `(condominium_id, user_id) → condominium_users` |
| Fast feed and per-category feed | `ix_posts_feed (condominium_id, status, id)` and `ix_posts_category_feed (condominium_id, category_id, status, id)` |
| Latest 3 comments per post (window query) | `ix_post_comments_thread (condominium_id, post_id, status, id)` |
| Moderation queue | `ix_post_reports_queue (condominium_id, status, created_at)` and the `uq_post_reports_once` prefix `(condominium_id, post_id)` |

Optionally, to add the brief's "General" category, run the following. No code change is needed:

```sql
INSERT INTO koinon.social_categories (code, name, sort_order) VALUES ('general', 'Geral', 5);
```

If you add it, also add a `.c-badge--general` / `.c-chip--general` colour in `community.css` and append `'general'` to `KNOWN_CATEGORIES` in `render.js`. Until then the badge uses the neutral style.

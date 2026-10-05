<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Page number / size arithmetic for paginated lists (users, units, invoices,
 * audit log, condominiums).
 *
 * The page comes from the query string and is clamped to a sane range, so
 * "?page=-5" or "?page=999999999" can never produce a negative or absurd
 * OFFSET. Models bind limit() and offset() as integers.
 */
final class Pagination
{
    /** Hard ceiling for page numbers: OFFSET values beyond this are never useful. */
    private const MAX_PAGE = 10000;

    private int $total = 0;

    private function __construct(
        private readonly int $page,
        private readonly int $perPage
    ) {
    }

    /** Reads ?page= from the request (default 1). */
    public static function fromRequest(Request $request, int $perPage = 25): self
    {
        $raw = $request->queryString('page');
        $page = preg_match('/^\d{1,5}$/', $raw) === 1 ? (int) $raw : 1;

        return new self(max(1, min($page, self::MAX_PAGE)), max(1, $perPage));
    }

    /** Stores the total number of rows (from the model's COUNT query). */
    public function withTotal(int $total): self
    {
        $clone = clone $this;
        $clone->total = max(0, $total);

        return $clone;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * Shape sent to JavaScript and to the pagination partial.
     *
     * @return array{page: int, per_page: int, total: int, pages: int}
     */
    public function toArray(): array
    {
        return [
            'page'     => $this->page,
            'per_page' => $this->perPage,
            'total'    => $this->total,
            'pages'    => $this->pages(),
        ];
    }
}

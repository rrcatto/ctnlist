<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * A page of a v5 paginated list. Rows per page come from ?r= (1-200,
 * default 10); a page number outside the range is clamped to it.
 */
final class Pagination
{
    private function __construct(
        public readonly int $page,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }

    public static function fromRequest(Request $request, int $page, int $total): self
    {
        $perPage = max(1, min(200, $request->query->getInt('r') ?: 10));
        $lastPage = max(1, (int) ceil($total / $perPage));
        return new self(max(1, min($page, $lastPage)), $lastPage, $perPage, $total);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}

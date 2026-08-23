<?php

declare(strict_types=1);

namespace App\Api\Shared\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The paging half of every list endpoint's query string.
 *
 * Shared rather than repeated so that all list endpoints page identically — a client that
 * learns `?page=2&perPage=50` once knows every collection in the API. Endpoint-specific
 * filters (a search term, a type) belong on the concrete subclass, because they differ.
 *
 * Offset/limit rather than a cursor: the repositories this feeds already take an offset
 * (UserRepository::findAllPaginated, SecurityEventRepository::findRecent), the admin UI shows
 * numbered pages rather than infinite scroll, and ACL filtering happens in SQL so LIMIT is
 * applied to rows the caller may already see.
 *
 * `perPage` is capped. An uncapped page size is a denial-of-service parameter: one request for
 * a million rows costs the same to send as one for ten.
 */
abstract readonly class PageRequest
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: self::MAX_PER_PAGE)]
        public int $perPage = 25,
    ) {
    }

    final public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    final public function limit(): int
    {
        return $this->perPage;
    }
}

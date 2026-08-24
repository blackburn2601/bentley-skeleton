<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use App\Account\Domain\UserStatus;
use App\Api\Shared\Request\PageRequest;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/admin/users.
 *
 * Paging comes from PageRequest so every collection in the API pages identically (ADR-0019);
 * the two filters below are this endpoint's own.
 */
final readonly class ListUsersRequest extends PageRequest
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        /**
         * Free-text match against the username or the account id.
         *
         * Either, not one or the other: the list shows the id first, so a fragment pasted from
         * a log line has to find its row. The id side matches any part of the canonical UUID
         * text, not just its start — UUIDv7 ids are time-ordered, so accounts created together
         * share a prefix and it is the tail that tells them apart.
         *
         * Capped, because it reaches a LIKE: an unbounded pattern is a scan someone else pays
         * for.
         */
        #[Assert\Length(max: 254)]
        public ?string $q = null,
        #[Assert\Choice(callback: [UserStatus::class, 'names'])]
        public ?string $status = null,
    ) {
        parent::__construct($page, $perPage);
    }
}

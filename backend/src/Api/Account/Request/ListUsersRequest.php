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
         * Free-text match against the email address.
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

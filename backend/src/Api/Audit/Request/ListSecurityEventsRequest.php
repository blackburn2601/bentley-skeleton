<?php

declare(strict_types=1);

namespace App\Api\Audit\Request;

use App\Api\Shared\Request\PageRequest;
use App\Shared\Domain\SecurityEventType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/admin/audit-events.
 */
final readonly class ListSecurityEventsRequest extends PageRequest
{
    public function __construct(
        int $page = 1,
        int $perPage = 25,
        /** A single event type to filter by, using its wire value. */
        #[Assert\Choice(callback: [SecurityEventType::class, 'names'])]
        public ?string $type = null,
    ) {
        parent::__construct($page, $perPage);
    }
}

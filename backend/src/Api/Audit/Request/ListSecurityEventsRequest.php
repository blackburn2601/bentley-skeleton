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
        /**
         * Free-text match against the event type, the actor id, the IP address and the request id.
         *
         * A substring anywhere, not just a prefix: an operator reading a log line or a support
         * ticket holds a fragment, not a whole value, and the audit log is exactly where fragments
         * arrive from. Case-insensitive on both ends. Capped, because it reaches a LIKE — an
         * unbounded pattern is a scan someone else pays for.
         */
        #[Assert\Length(max: 254)]
        public ?string $q = null,
        /** A single event type to filter by, using its wire value. */
        #[Assert\Choice(callback: [SecurityEventType::class, 'names'])]
        public ?string $type = null,
    ) {
        parent::__construct($page, $perPage);
    }
}

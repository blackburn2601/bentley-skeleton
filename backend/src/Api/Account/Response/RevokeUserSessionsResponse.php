<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

final readonly class RevokeUserSessionsResponse
{
    private function __construct(public int $sessionsRevoked)
    {
    }

    public static function from(int $sessionsRevoked): self
    {
        return new self($sessionsRevoked);
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Audit\Response;

final readonly class EraseUserResponse
{
    private function __construct(
        public bool $erased,
        public int $sessionsRevoked,
    ) {
    }

    public static function from(bool $erased, int $sessionsRevoked): self
    {
        return new self($erased, $sessionsRevoked);
    }
}

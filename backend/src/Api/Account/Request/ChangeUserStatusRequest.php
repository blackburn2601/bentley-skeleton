<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use App\Account\Domain\UserStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PATCH /api/v1/admin/users/{id}/status.
 */
final readonly class ChangeUserStatusRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [UserStatus::class, 'names'])]
        public string $status = '',
    ) {
    }
}

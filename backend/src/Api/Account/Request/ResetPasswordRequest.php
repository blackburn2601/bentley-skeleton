<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use App\Account\Domain\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ResetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $token = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
        public string $password = '',
    ) {
    }
}

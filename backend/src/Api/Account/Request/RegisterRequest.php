<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use App\Account\Domain\PasswordPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
        #[Assert\Length(max: 254)]
        public string $email = '',
        // Only the structural bounds are here. The interesting rules — breach checks,
        // similarity to the email — live in PasswordPolicy, in the Domain, where they are
        // unit-testable and shared with the reset flow rather than duplicated per endpoint.
        #[Assert\NotBlank]
        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
        public string $password = '',
    ) {
    }
}

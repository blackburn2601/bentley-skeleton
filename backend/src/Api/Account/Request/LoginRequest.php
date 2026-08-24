<?php

declare(strict_types=1);

namespace App\Api\Account\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class LoginRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 64)]
        public string $username = '',
        // Deliberately no minimum length: a login form must not tell an attacker that the
        // password they guessed was "too short to be ours". Validity is decided by whether
        // it matches, and by nothing else.
        #[Assert\NotBlank]
        public string $password = '',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\BreachedPasswordChecker;
use App\Account\Domain\AccountException;
use App\Account\Domain\PasswordPolicy;

/**
 * @responsibility Refuses a password that this system will not accept.
 *
 * User creation, password reset and self-service change must apply identical rules — a reset
 * that accepted weaker passwords than creation would be a way around the policy, and the two
 * drifting apart is exactly what happens when each flow checks for itself. One service, used
 * by all of them.
 */
final readonly class AssertPasswordAcceptableService
{
    public function __construct(
        private PasswordPolicy $policy,
        private BreachedPasswordChecker $breachCheck,
    ) {
    }

    /**
     * @throws AccountException if the password is unacceptable
     */
    public function __invoke(string $plainPassword, string $username): void
    {
        // Structural rules first: they are free, and there is no reason to ask a third party
        // about a password we are going to refuse anyway.
        $violations = $this->policy->violations($plainPassword, $username);

        if ([] !== $violations) {
            throw AccountException::weakPassword($violations);
        }

        if ($this->breachCheck->isBreached($plainPassword)) {
            throw AccountException::breachedPassword();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Account\Application;

use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Exposes the Account context to other contexts as a single narrow surface.
 *
 * Read-only, deliberately. Other contexts legitimately need to know that a user id resolves
 * to a real, active account — the admin ACL screens list people by email — but changing an
 * account is Account's own business, behind its own endpoints and its own audit events.
 */
final readonly class AccountFacade
{
    public function __construct(private UserRepository $users)
    {
    }

    public function findById(Uuid $userId): ?User
    {
        return $this->users->findById($userId);
    }

    public function emailOf(Uuid $userId): ?string
    {
        return $this->users->findById($userId)?->email();
    }

    public function exists(Uuid $userId): bool
    {
        return null !== $this->users->findById($userId);
    }
}

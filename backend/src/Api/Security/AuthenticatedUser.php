<?php

declare(strict_types=1);

namespace App\Api\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The caller, as the security layer sees them.
 *
 * Deliberately not the `User` entity. Three reasons, in order of importance:
 *
 *  1. **No permissions live here.** Symfony caches the token for the request, and a
 *     permission list on it would be a second, stale source of truth beside the resolver
 *     (ADR-0011).
 *  2. It keeps the Api layer from holding a Doctrine entity across the whole request, which
 *     is how detached-entity bugs start.
 *  3. It is cheap: built straight from JWT claims, with no database round trip on requests
 *     that never need the full user.
 *
 * `roles` here are Symfony roles (ROLE_*), used only for framework-level checks. Application
 * permissions are always resolved through the ACL.
 */
final readonly class AuthenticatedUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private Uuid $id,
        private string $email,
        private array $roles = [],
        private int $aclVersion = 1,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function aclVersion(): int
    {
        return $this->aclVersion;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getUserIdentifier(): string
    {
        return $this->id->toRfc4122();
    }

    public function eraseCredentials(): void
    {
        // Nothing to erase: this object never holds credentials.
    }
}

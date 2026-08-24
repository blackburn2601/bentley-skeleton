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
 *
 * MFA state (ADR-0026) is read off the signed claims, so it cannot be tampered with: `amr`
 * carries the completed authentication methods (`'totp'` once verified) and `mfa` carries the
 * stage. The {@see MfaStageVoter} denies everything while the stage is pending.
 */
final readonly class AuthenticatedUser implements UserInterface
{
    /**
     * @param list<string> $roles
     * @param list<string> $amr
     */
    public function __construct(
        private Uuid $id,
        private string $username,
        private array $roles = [],
        private int $aclVersion = 1,
        private array $amr = [],
        private ?string $mfa = null,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function aclVersion(): int
    {
        return $this->aclVersion;
    }

    /** @return list<string> */
    public function amr(): array
    {
        return $this->amr;
    }

    /**
     * The stage the caller is in. Pending only when the signed `mfa` claim is literally
     * `'pending'`; everything else — verified, or a session that never owed a second factor
     * — reads as Verified (free to act).
     */
    public function mfaStage(): MfaStage
    {
        return 'pending' === $this->mfa ? MfaStage::Pending : MfaStage::Verified;
    }

    /** Did the caller complete a TOTP second factor on this session? */
    public function mfaVerified(): bool
    {
        return \in_array('totp', $this->amr, true);
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

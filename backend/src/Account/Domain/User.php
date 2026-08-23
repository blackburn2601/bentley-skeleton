<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: '"user"')]
#[ORM\Index(name: 'idx_user_status', columns: ['status'])]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', enumType: UserStatus::class)]
    private UserStatus $status = UserStatus::PendingVerification;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $emailVerifiedAt = null;

    /**
     * Encrypted at rest, not merely hashed: TOTP verification needs the original secret
     * back, so it cannot be one-way.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(type: 'integer')]
    private int $failedLoginCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

    /**
     * Bumped whenever anything that could change this user's effective permissions changes:
     * a role, a group membership, an ACE.
     *
     * This is what makes revocation immediate without a cache sweep (ADR-0011). The ACL
     * cache key contains this number, so a bump orphans every stale entry atomically, and a
     * concurrent reader either sees the old key (and recomputes) or the new one. There is no
     * window in which a stale permission is served, and nothing has to be invalidated.
     */
    #[ORM\Column(type: 'integer')]
    private int $aclVersion = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    // Roles and group memberships are deliberately NOT modelled here.
    //
    // They belong to the Acl context, which owns `role_assignment` and `group_membership`
    // and keys them by subject id rather than by entity reference — exactly as `acl_entry`
    // already does. Mapping them as associations on User would make Account depend on Acl's
    // internals, which deptrac rejects, and would give two contexts write access to the same
    // authorization state.
    //
    // Ask the Acl context instead: `AclFacade::directRoleNamesOf($userId)`. The database still carries
    // foreign keys to `"user"` — that constraint is defined in the migration, where it costs
    // nothing and buys referential integrity.

    public function __construct(/**
     * citext, so uniqueness is case-insensitive in the database.
     *
     * Lowercasing in PHP would work right up until the one query that forgets, which then
     * creates a second account differing only in capitalisation — and now a password reset
     * is ambiguous. The database is the only place this can be enforced once.
     */
        #[ORM\Column(type: 'citext', unique: true)]
        private string $email,
        #[ORM\Column(type: 'string')]
        private string $passwordHash,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $passwordChangedAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $this->passwordChangedAt;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function aclVersion(): int
    {
        return $this->aclVersion;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function passwordChangedAt(): DateTimeImmutable
    {
        return $this->passwordChangedAt;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function hasMfaEnabled(): bool
    {
        return null !== $this->totpSecretEncrypted;
    }

    public function totpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function isLockedAt(DateTimeImmutable $now): bool
    {
        return $this->lockedUntil instanceof DateTimeImmutable && $this->lockedUntil > $now;
    }

    public function lockedUntil(): ?DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function failedLoginCount(): int
    {
        return $this->failedLoginCount;
    }

    /**
     * Move the account to a different address, at an administrator's request.
     *
     * Verification is deliberately reset. The new address is unproven — nobody has
     * demonstrated they can receive mail there — and leaving the old `emailVerifiedAt` in place
     * would let a typo silently become a "verified" address that password resets are sent to.
     *
     * The status is left alone: an Active account stays Active, so this does not become a way
     * to lock someone out by editing their profile.
     */
    public function changeEmail(string $email): void
    {
        if ($email === $this->email) {
            return;
        }

        $this->email = $email;
        $this->emailVerifiedAt = null;
    }

    public function verifyEmail(DateTimeImmutable $now): void
    {
        $this->emailVerifiedAt = $now;

        if (UserStatus::PendingVerification === $this->status) {
            $this->status = UserStatus::Active;
        }
    }

    public function recordFailedLogin(DateTimeImmutable $lockedUntil): void
    {
        ++$this->failedLoginCount;
        $this->lockedUntil = $lockedUntil;
    }

    public function recordSuccessfulLogin(): void
    {
        $this->failedLoginCount = 0;
        $this->lockedUntil = null;
    }

    public function changePassword(string $passwordHash, DateTimeImmutable $now): void
    {
        $this->passwordHash = $passwordHash;
        $this->passwordChangedAt = $now;
    }

    public function enableMfa(string $totpSecretEncrypted): void
    {
        $this->totpSecretEncrypted = $totpSecretEncrypted;
    }

    public function disableMfa(): void
    {
        $this->totpSecretEncrypted = null;
    }

    public function suspend(): void
    {
        $this->status = UserStatus::Suspended;
    }

    public function reinstate(): void
    {
        $this->status = UserStatus::Active;
    }

    /**
     * Invalidate every cached permission decision for this user.
     *
     * Called by the Acl context whenever a grant changes. Cheap on purpose: an integer
     * increment, no cache traversal, safe under concurrency.
     */
    public function bumpAclVersion(): void
    {
        ++$this->aclVersion;
    }

    public function anonymise(string $placeholderEmail, DateTimeImmutable $now): void
    {
        $this->email = $placeholderEmail;
        $this->passwordHash = '';
        $this->totpSecretEncrypted = null;
        $this->status = UserStatus::Anonymised;
        $this->deletedAt = $now;
    }
}

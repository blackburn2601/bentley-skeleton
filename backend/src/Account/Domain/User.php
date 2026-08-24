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
    private UserStatus $status = UserStatus::Active;

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

    /**
     * The user's TOTP secret, encrypted at rest (ADR-0026). NULL until the user enrolls and
     * confirms. The secret the user is *about* to enroll is held in
     * {@see $totpSecretEncryptedProvisional} until the first code confirms the app captured it.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecretEncrypted = null;

    /**
     * A secret the user has begun enrolling but not yet confirmed. Replaced by
     * {@see $totpSecretEncrypted} on confirmation, or cleared on disable/reset. Keeping it
     * separate means a confirmed, working second factor is never disturbed by an abandoned
     * enrollment attempt.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $totpSecretEncryptedProvisional = null;

    /**
     * An administrator has required MFA for this account (ADR-0026). Set independently of the
     * secret: required-but-unenrolled blocks login (the user enrolls inside an authenticated
     * session, not at the login prompt), and a self-disabled factor leaves the requirement in
     * place because removing the device is the user's choice, not a relaxation of policy.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $mfaRequired = false;

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
     * creates a second account differing only in capitalisation. The database is the only place
     * this can be enforced once.
     */
        #[ORM\Column(type: 'citext', unique: true)]
        private string $username,
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

    public function username(): string
    {
        return $this->username;
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

    public function totpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function totpSecretEncryptedProvisional(): ?string
    {
        return $this->totpSecretEncryptedProvisional;
    }

    public function isMfaRequired(): bool
    {
        return $this->mfaRequired;
    }

    /**
     * Does MFA apply to this account at login?
     *
     * True when the administrator has required it OR the user has enrolled a factor. Floor
     * users with neither are untouched — they log in exactly as in ADR-0024. This is the
     * single switch {@see \App\Account\Application\Service\SignInService} branches on.
     */
    public function mfaApplies(): bool
    {
        return $this->mfaRequired || null !== $this->totpSecretEncrypted;
    }

    public function hasEnrolledTotp(): bool
    {
        return null !== $this->totpSecretEncrypted;
    }

    /**
     * Hold a secret the user has begun enrolling, pending confirmation by a first code.
     */
    public function beginTotpEnrollment(string $secretEncrypted): void
    {
        $this->totpSecretEncryptedProvisional = $secretEncrypted;
    }

    /**
     * Promote the provisional secret to the live one, on confirmation by a valid code.
     */
    public function confirmTotpEnrollment(): void
    {
        if (null === $this->totpSecretEncryptedProvisional) {
            return;
        }

        $this->totpSecretEncrypted = $this->totpSecretEncryptedProvisional;
        $this->totpSecretEncryptedProvisional = null;
    }

    public function clearTotpSecret(): void
    {
        $this->totpSecretEncrypted = null;
        $this->totpSecretEncryptedProvisional = null;
    }

    public function requireMfa(): void
    {
        $this->mfaRequired = true;
    }

    public function clearMfaRequirement(): void
    {
        $this->mfaRequired = false;
    }

    /**
     * Move the account to a different username, at an administrator's request.
     *
     * The status is left alone: an Active account stays Active, so this does not become a way
     * to lock someone out by editing their profile.
     */
    public function changeUsername(string $username): void
    {
        if ($username === $this->username) {
            return;
        }

        $this->username = $username;
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

    public function anonymise(string $placeholderUsername, DateTimeImmutable $now): void
    {
        $this->username = $placeholderUsername;
        $this->passwordHash = '';
        $this->status = UserStatus::Anonymised;
        $this->deletedAt = $now;
        // An erased account leaves no second factor behind either.
        $this->totpSecretEncrypted = null;
        $this->totpSecretEncryptedProvisional = null;
        $this->mfaRequired = false;
    }
}

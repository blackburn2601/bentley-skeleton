<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One link in a rotating refresh-token chain (ADR-0002).
 *
 * Every use of a refresh token issues a successor and marks the presented one used. All the
 * successors of one login share a `familyId`, and that is what makes theft *detectable*:
 *
 *   - Legitimate use presents the newest token. It rotates. Nothing happens.
 *   - A stolen token is used once by the attacker, rotating the family. The real client then
 *     presents the token it still holds — now already rotated — and that reuse revokes the
 *     entire family. Both parties are logged out and a `refresh_token_reuse` event is
 *     recorded.
 *
 * Without rotation, a stolen token simply works for its full 30 days and nobody ever knows.
 * The cost of this design is that a client which genuinely refreshes twice concurrently also
 * trips the alarm — which is why the SPA's fetch wrapper does single-flight refresh.
 *
 * The token value itself is never stored; only its hash (see TokenHash).
 */
#[ORM\Entity]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(name: 'idx_refresh_token_family', columns: ['family_id'])]
#[ORM\Index(name: 'idx_refresh_token_user', columns: ['user_id'])]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Shared by every token descended from one login. Revoked as a unit. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $familyId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $parentId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** Set when this token is rotated. A second presentation after this is reuse. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $replacedBy = null;

    /** Device metadata, for the "your active sessions" screen. Never trusted for decisions. */
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent;

    public function __construct(
        string $tokenHash,
        User $user,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
        ?Uuid $familyId = null,
        ?Uuid $parentId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ) {
        $this->id = Uuid::v7();
        $this->tokenHash = $tokenHash;
        $this->user = $user;
        // A token with no parent starts its own family: this is a fresh login.
        $this->familyId = $familyId ?? $this->id;
        $this->parentId = $parentId;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
        $this->ipAddress = $ipAddress;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function familyId(): Uuid
    {
        return $this->familyId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    /** Usable exactly once, and only while live. */
    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isRevoked() && !$this->isExpiredAt($now);
    }

    public function markUsed(DateTimeImmutable $now, Uuid $successorId): void
    {
        $this->usedAt = $now;
        $this->replacedBy = $successorId;
    }

    public function revoke(DateTimeImmutable $now): void
    {
        $this->revokedAt ??= $now;
    }
}

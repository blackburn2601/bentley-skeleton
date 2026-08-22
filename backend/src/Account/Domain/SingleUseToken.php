<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A short-lived, single-use secret sent to an email address.
 *
 * One table for email verification and password reset rather than two: they have identical
 * mechanics — hashed value, short TTL, consumed once, tied to a user — and differ only in
 * purpose. Two near-identical entities would mean two places to get the expiry check wrong.
 *
 * Only the hash is stored (see TokenHash): a database backup must not hand over the ability
 * to reset every pending account.
 */
#[ORM\Entity]
#[ORM\Table(name: 'single_use_token')]
#[ORM\Index(name: 'idx_single_use_token_user_purpose', columns: ['user_id', 'purpose'])]
class SingleUseToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $consumedAt = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'string', enumType: TokenPurpose::class)]
        private TokenPurpose $purpose,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function user(): User
    {
        return $this->user;
    }

    public function purpose(): TokenPurpose
    {
        return $this->purpose;
    }

    public function isUsableAt(DateTimeImmutable $now, TokenPurpose $expected): bool
    {
        return !$this->consumedAt instanceof DateTimeImmutable
            && $this->purpose === $expected
            && $this->expiresAt > $now;
    }

    public function consume(DateTimeImmutable $now): void
    {
        $this->consumedAt = $now;
    }
}

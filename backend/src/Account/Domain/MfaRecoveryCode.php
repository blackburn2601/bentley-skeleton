<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One single-use MFA recovery code for a user (ADR-0026).
 *
 * Stored as a SHA-256 hash, never plaintext: a recovery code is a bearer secret like a refresh
 * token — whoever holds it can spend it — so a database dump must not hand over the live codes.
 * Burned on use (`usedAt`), and the plaintext is shown to the user exactly once, at enrollment.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mfa_recovery_code')]
#[ORM\Index(name: 'idx_mfa_recovery_code_user', columns: ['user_id'])]
class MfaRecoveryCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64, unique: true)]
        private string $codeHash,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function codeHash(): string
    {
        return $this->codeHash;
    }

    public function isUsed(): bool
    {
        return $this->usedAt instanceof DateTimeImmutable;
    }

    public function markUsed(DateTimeImmutable $now): void
    {
        $this->usedAt ??= $now;
    }
}

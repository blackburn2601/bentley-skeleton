<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface RefreshTokenRepository
{
    public function findByHash(string $tokenHash): ?RefreshToken;

    public function save(RefreshToken $token): void;

    /**
     * Revoke every token descended from one login.
     *
     * The reuse-detection hammer: presenting an already-rotated token kills the whole chain,
     * because we cannot tell the thief from the victim and must assume both.
     *
     * @return int how many were revoked
     */
    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): int;

    /** @return int how many were revoked */
    public function revokeAllForUser(Uuid $userId, DateTimeImmutable $now): int;

    /**
     * Live sessions for the "your devices" screen: one row per family, newest first.
     *
     * @return list<RefreshToken>
     */
    public function findActiveSessionsForUser(Uuid $userId, DateTimeImmutable $now): array;

    public function deleteExpired(DateTimeImmutable $before): int;
}

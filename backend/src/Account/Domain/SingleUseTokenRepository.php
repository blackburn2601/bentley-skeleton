<?php

declare(strict_types=1);

namespace App\Account\Domain;

use DateTimeImmutable;

interface SingleUseTokenRepository
{
    /** Lookup is by hash: the plaintext is never stored, so there is nothing else to match on. */
    public function findByHash(string $tokenHash): ?SingleUseToken;

    public function save(SingleUseToken $token): void;

    /** Invalidate any live token of this purpose, so only the newest link works. */
    public function consumeOutstanding(User $user, TokenPurpose $purpose, DateTimeImmutable $now): void;

    /** Housekeeping: expired tokens are of no further use to anyone. */
    public function deleteExpired(DateTimeImmutable $before): int;
}

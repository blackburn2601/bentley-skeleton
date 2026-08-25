<?php

declare(strict_types=1);

namespace App\Account\Domain;

use Symfony\Component\Uid\Uuid;

interface MfaRecoveryCodeRepository
{
    public function save(MfaRecoveryCode $code): void;

    /** Remove every recovery code a user holds — on disable, reset, or re-enrollment. */
    public function deleteAllForUser(Uuid $userId): void;

    /**
     * Find a code by its hash, scoped to one user, for verification.
     *
     * Scoping on the user is load-bearing: a recovery code is globally unique, but the caller
     * is identified by the challenge token's `sub`. A code that belongs to a different user
     * must never be spendable against this caller's challenge, so the lookup is keyed on both.
     *
     * Returns the matching code (regardless of used state, so the caller can detect reuse) or
     * null. The caller marks it used on a successful, unused match.
     */
    public function findForUser(Uuid $userId, string $codeHash): ?MfaRecoveryCode;
}

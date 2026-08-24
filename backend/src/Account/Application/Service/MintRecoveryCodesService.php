<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\MfaRecoveryCode;
use App\Account\Domain\MfaRecoveryCodeRepository;
use App\Account\Domain\User;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\TokenHash;

/**
 * @responsibility Mints a fresh set of single-use recovery codes for a user.
 *
 * Replaces any codes a previous enrollment left behind, then mints ten new ones. Only the
 * SHA-256 hashes are kept; the plaintext is returned once so the caller can show it. The
 * numeric format comes from {@see SecretGenerator::generateNumericCode} — long enough to be
 * unguessable, short enough to read aloud and type (ADR-0026).
 */
final readonly class MintRecoveryCodesService
{
    private const int COUNT = 10;

    public function __construct(
        private MfaRecoveryCodeRepository $recoveryCodes,
        private SecretGenerator $secrets,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<string> the plaintext recovery codes, shown exactly once
     */
    public function __invoke(User $user): array
    {
        $this->recoveryCodes->deleteAllForUser($user->id());

        $now = $this->clock->now();
        $codes = [];

        for ($i = 0; $i < self::COUNT; ++$i) {
            $plaintext = $this->secrets->generateNumericCode(10);
            $this->recoveryCodes->save(new MfaRecoveryCode(TokenHash::of($plaintext)->value, $user, $now));
            $codes[] = $plaintext;
        }

        return $codes;
    }
}

<?php

declare(strict_types=1);

namespace App\Account\Domain;

/**
 * What counts as an acceptable password here.
 *
 * The rules are deliberately close to current NIST guidance rather than the older
 * "one uppercase, one digit, one symbol" style: composition rules push people towards
 * `Password1!` — which satisfies every rule and is among the first guesses any attacker
 * makes — while length and a breach check do the actual work.
 *
 * Kept in Domain, and free of I/O, so it is exhaustively unit-testable. The breach check
 * lives behind a separate port because it *is* I/O.
 */
final readonly class PasswordPolicy
{
    public const int MINIMUM_LENGTH = 12;

    /**
     * 4096 is PHP's password_hash limit for bcrypt and a sane guard generally: without an
     * upper bound, a multi-megabyte password is a denial-of-service against argon2id, which
     * is expensive by design.
     */
    public const int MAXIMUM_LENGTH = 4096;

    /**
     * @return list<string> the reasons it is unacceptable; empty means acceptable
     */
    public function violations(string $password, string $username): array
    {
        $violations = [];
        $length = mb_strlen($password);

        if ($length < self::MINIMUM_LENGTH) {
            $violations[] = \sprintf('It must be at least %d characters long.', self::MINIMUM_LENGTH);
        }

        if ($length > self::MAXIMUM_LENGTH) {
            $violations[] = \sprintf('It must be at most %d characters long.', self::MAXIMUM_LENGTH);
        }

        if ($this->resemblesUsername($password, $username)) {
            $violations[] = 'It must not contain your username.';
        }

        if ($this->isTooRepetitive($password)) {
            $violations[] = 'It must not be a single character or a short pattern repeated.';
        }

        return $violations;
    }

    private function resemblesUsername(string $password, string $username): bool
    {
        if (mb_strlen($username) < 3) {
            return false;
        }

        return str_contains(mb_strtolower($password), mb_strtolower($username));
    }

    /**
     * Catches "aaaaaaaaaaaa" and "abcabcabcabc": long enough to pass the length rule, and
     * trivially guessable.
     *
     * Only patterns repeated at least three times count. Without that floor, a six-character
     * unit repeated twice would be flagged, and "correcthorse" is a perfectly good start to a
     * passphrase.
     */
    private function isTooRepetitive(string $password): bool
    {
        $length = mb_strlen($password);

        for ($unit = 1; $unit <= 4; ++$unit) {
            if ($length < $unit * 3) {
                continue;
            }

            $prefix = mb_substr($password, 0, $unit);
            $repeats = (int) ceil($length / $unit);

            if (mb_substr(str_repeat($prefix, $repeats), 0, $length) === $password) {
                return true;
            }
        }

        return false;
    }
}

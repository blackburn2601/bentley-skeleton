<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * Hashing and verifying passwords.
 *
 * A port so tests can substitute a fast hasher. argon2id at production cost takes tens of
 * milliseconds by design, and a functional suite that logs in a hundred times would spend
 * most of its runtime deriving keys — which is how "just skip the auth tests" starts.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $hash, string $plainPassword): bool;

    /**
     * Should this hash be recomputed?
     *
     * True when the cost parameters have been raised since it was written. Rehashing on a
     * successful login is the only moment the plaintext is available, so it is the only
     * chance to upgrade an old hash without asking the user to do anything.
     */
    public function needsRehash(string $hash): bool;
}

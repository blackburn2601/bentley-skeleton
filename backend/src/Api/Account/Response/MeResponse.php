<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * The current caller, as the SPA needs them.
 *
 * `permissions` is included so the UI can hide controls the user cannot use — and it is
 * **advisory only** (INV-16). The browser is not a trust boundary; every endpoint enforces
 * the same permission server-side, and the IDOR suite exists because that gets forgotten.
 */
final readonly class MeResponse
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
        public bool $mfaEnabled,
        public array $roles,
        public array $permissions,
    ) {
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public static function from(
        string $id,
        string $email,
        bool $emailVerified,
        bool $mfaEnabled,
        array $roles,
        array $permissions,
    ): self {
        return new self($id, $email, $emailVerified, $mfaEnabled, $roles, $permissions);
    }
}

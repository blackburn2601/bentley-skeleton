<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * The current caller, as the SPA needs them.
 *
 * `permissions` is included so the UI can hide controls the user cannot use — and it is
 * **advisory only** (INV-16). The browser is not a trust boundary; every endpoint enforces
 * the same permission server-side, and the IDOR suite exists because that gets forgotten.
 *
 * `mfaEnrolled` and `mfaRequired` let the account screen show the second-factor state without
 * a separate round trip (ADR-0026). Like `permissions`, they are advisory: the verify and
 * enrol endpoints re-check the live row server-side.
 */
final readonly class MeResponse
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function __construct(
        public string $id,
        public string $username,
        public array $roles,
        public array $permissions,
        public bool $mfaEnrolled,
        public bool $mfaRequired,
    ) {
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public static function from(
        string $id,
        string $username,
        array $roles,
        array $permissions,
        bool $mfaEnrolled,
        bool $mfaRequired,
    ): self {
        return new self($id, $username, $roles, $permissions, $mfaEnrolled, $mfaRequired);
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * What a successful authentication tells the client.
 *
 * Note what is absent: the tokens. They travel in HttpOnly cookies and must never appear in
 * a response body, where any script on the page could read them (ADR-0002).
 *
 * `mfaRequired` is always present in the body — the client branches on a value, not on a
 * missing key, and a half-authenticated state must read differently from "no factor at all":
 *  - `false`     — no factor applies; a floor user with a full session.
 *  - `'pending'` — the password checked out but a second factor is owed; no refresh cookie is
 *                  set in this state (ADR-0026), so the client switches to the MFA verify screen.
 *  - `'verified'`— a full session reached after proving a second factor.
 */
final readonly class SessionResponse
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public string $id,
        public string $username,
        public array $roles,
        public string|false $mfaRequired,
    ) {
    }

    /**
     * @param list<string> $roles
     * @param string|false $mfaRequired 'verified' after a second factor, false for a floor user
     */
    public static function authenticated(string $id, string $username, array $roles, string|false $mfaRequired): self
    {
        return new self($id, $username, $roles, $mfaRequired);
    }

    public static function pending(string $id, string $username): self
    {
        return new self($id, $username, [], 'pending');
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * What a successful authentication tells the client.
 *
 * Note what is absent: the tokens. They travel in HttpOnly cookies and must never appear in
 * a response body, where any script on the page could read them (ADR-0002).
 */
final readonly class SessionResponse
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        public string $id,
        public string $email,
        public array $roles,
        public bool $mfaRequired = false,
    ) {
    }

    /**
     * @param list<string> $roles
     */
    public static function authenticated(string $id, string $email, array $roles): self
    {
        return new self($id, $email, $roles);
    }
}

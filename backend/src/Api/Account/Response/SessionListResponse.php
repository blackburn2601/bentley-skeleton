<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * The caller's active sessions, for a "where am I signed in?" screen.
 *
 * Deliberately contains no token value, not even a truncated one: the list exists so someone
 * can spot a session they do not recognise and end it, and a screen that displays credentials
 * is a screen that leaks them over someone's shoulder.
 */
final readonly class SessionListResponse
{
    /**
     * @param list<array{id: string, createdAt: string, ipAddress: string|null, userAgent: string|null, current: bool}> $sessions
     */
    private function __construct(public array $sessions)
    {
    }

    /**
     * @param list<array{id: string, createdAt: string, ipAddress: string|null, userAgent: string|null, current: bool}> $sessions
     */
    public static function from(array $sessions): self
    {
        return new self($sessions);
    }
}

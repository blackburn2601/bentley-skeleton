<?php

declare(strict_types=1);

namespace App\Audit\Domain;

use App\Shared\Domain\DomainProblem;
use App\Shared\Domain\ProblemKind;
use RuntimeException;

/**
 * An erasure the Audit context will not perform.
 */
final class EraseRefused extends RuntimeException implements DomainProblem
{
    private function __construct(string $message, private readonly ProblemKind $kind)
    {
        parent::__construct($message);
    }

    public function kind(): ProblemKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [];
    }

    /**
     * Erasure is irreversible and revokes every session, so an administrator doing it to
     * themselves destroys the account they would need in order to undo it. The self-service
     * route at DELETE /api/v1/me exists for someone who genuinely means it.
     */
    public static function ownAccount(): self
    {
        return new self(
            'Use the account page to erase your own account.',
            ProblemKind::Conflict,
        );
    }

    public static function noSuchAccount(): self
    {
        return new self('No such account.', ProblemKind::NotFound);
    }
}

<?php

declare(strict_types=1);

namespace App\Account\Domain;

use App\Shared\Domain\DomainProblem;
use App\Shared\Domain\ProblemKind;
use DateTimeImmutable;
use RuntimeException;

/**
 * Something the Account context refuses to do.
 *
 * Domain exceptions, not HTTP ones (INV-08, INV-17). The problem+json listener is the single
 * place that turns these into status codes, so a service stays callable from a console
 * command or a test without pretending to be a web request.
 *
 * The named constructors matter as much as the class: each carries the message the user will
 * actually see, so the wording of a security-sensitive response is decided once, here, rather
 * than at each of the several call sites that can produce it.
 */
final class AccountException extends RuntimeException implements DomainProblem
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        private readonly ProblemKind $kind = ProblemKind::Invalid,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function kind(): ProblemKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function invalidCredentials(): self
    {
        // Deliberately identical whether the account exists, the password is wrong, or the
        // address was never registered. Distinguishing them turns the login form into an
        // account-enumeration oracle.
        return new self('The email address or password is incorrect.', ProblemKind::Unauthenticated);
    }

    public static function accountLocked(DateTimeImmutable $until): self
    {
        return new self(\sprintf(
            'Too many failed attempts. Try again after %s UTC.',
            $until->format('H:i'),
        ));
    }

    public static function accountNotActive(): self
    {
        return new self(
            'This account cannot sign in. Verify your email address, or contact an administrator.',
            ProblemKind::Forbidden,
        );
    }

    public static function invalidToken(): self
    {
        return new self('This link is invalid or has expired. Request a new one.', ProblemKind::Unauthenticated);
    }

    public static function mfaRequired(): self
    {
        return new self('A second factor is required.', ProblemKind::Unauthenticated, ['mfaRequired' => true]);
    }

    public static function invalidMfaCode(): self
    {
        return new self('That code is not valid.', ProblemKind::Unauthenticated);
    }

    /** @param list<string> $violations */
    public static function weakPassword(array $violations): self
    {
        return new self(
            'That password cannot be used. '.implode(' ', $violations),
            ProblemKind::Invalid,
            ['violations' => $violations],
        );
    }

    public static function breachedPassword(): self
    {
        return new self(
            'That password has appeared in a public data breach and cannot be used. '
            .'Choose one you have not used elsewhere.',
            ProblemKind::Invalid,
        );
    }

    public static function emailAlreadyRegistered(): self
    {
        return new self('That email address cannot be registered.', ProblemKind::Conflict);
    }
}

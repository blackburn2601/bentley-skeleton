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
        // username was never registered. Distinguishing them turns the login form into an
        // account-enumeration oracle.
        return new self('The username or password is incorrect.', ProblemKind::Unauthenticated);
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
            'This account cannot sign in. Contact an administrator.',
            ProblemKind::Forbidden,
        );
    }

    public static function invalidToken(): self
    {
        return new self('This token is invalid or has expired.', ProblemKind::Unauthenticated);
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

    public static function usernameAlreadyRegistered(): self
    {
        return new self('That username cannot be registered.', ProblemKind::Conflict);
    }

    public static function noSuchAccount(): self
    {
        return new self('No such account.', ProblemKind::NotFound);
    }

    /**
     * An administrator may not suspend themselves.
     *
     * Not paternalism: suspension revokes every session, so the act would immediately lock the
     * actor out of the tool they would need to undo it — and if they hold the only account
     * with the permission, nobody can.
     */
    public static function cannotChangeOwnStatus(): self
    {
        return new self('You cannot change the status of your own account.', ProblemKind::Conflict);
    }

    public static function accountIsAnonymised(): self
    {
        return new self('This account has been erased. Its status is final.', ProblemKind::Conflict);
    }

    public static function statusNotSettable(string $status): self
    {
        return new self(
            \sprintf('"%s" is not a status an administrator can assign.', $status),
            ProblemKind::Invalid,
        );
    }

    /**
     * The same conflict, said plainly.
     *
     * usernameAlreadyRegistered() is deliberately vague because it can reach an anonymous caller,
     * where naming the reason turns the form into an account-enumeration oracle. An
     * administrator is already authorized to list every account, so withholding it from them
     * protects nothing and only leaves them waiting for an account that will never arrive.
     */
    public static function usernameAlreadyInUse(string $username): self
    {
        return new self(
            \sprintf('An account already exists for %s.', $username),
            ProblemKind::Conflict,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\Domain\User;
use App\Account\Domain\UserStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The User entity's state transitions.
 *
 * Small, pure, and worth having: these are the rules that decide whether somebody can sign
 * in, and they are the kind of thing that gets "simplified" by someone who does not know why
 * lockout and status are separate.
 */
#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    private const string PASSWORD_HASH = '$argon2id$fake';

    public function testANewUserCannotAuthenticateUntilVerified(): void
    {
        $user = $this->user();

        self::assertSame(UserStatus::PendingVerification, $user->status());
        self::assertFalse($user->status()->canAuthenticate());
        self::assertFalse($user->isEmailVerified());
    }

    public function testVerifyingActivatesTheAccount(): void
    {
        $user = $this->user();
        $user->verifyEmail($this->now());

        self::assertTrue($user->isEmailVerified());
        self::assertTrue($user->status()->canAuthenticate());
    }

    public function testVerifyingDoesNotResurrectASuspendedAccount(): void
    {
        $user = $this->user();
        $user->verifyEmail($this->now());
        $user->suspend();

        $user->verifyEmail($this->now());

        self::assertSame(
            UserStatus::Suspended,
            $user->status(),
            'Confirming an email address must not undo an administrative suspension — that '
            .'would make "verify your email" a way out of being suspended.',
        );
    }

    public function testLockoutIsTimeBoundedNotPermanent(): void
    {
        $user = $this->user();
        $until = $this->now()->modify('+5 minutes');
        $user->recordFailedLogin($until);

        self::assertTrue($user->isLockedAt($this->now()));
        self::assertFalse($user->isLockedAt($until->modify('+1 second')));
    }

    public function testASuccessfulLoginClearsTheLockout(): void
    {
        $user = $this->user();
        $user->recordFailedLogin($this->now()->modify('+1 hour'));
        $user->recordFailedLogin($this->now()->modify('+2 hours'));

        self::assertSame(2, $user->failedLoginCount());

        $user->recordSuccessfulLogin();

        self::assertSame(0, $user->failedLoginCount());
        self::assertFalse($user->isLockedAt($this->now()));
    }

    public function testChangingThePasswordRecordsWhen(): void
    {
        $user = $this->user();
        $later = $this->now()->modify('+1 day');

        $user->changePassword('$argon2id$new', $later);

        self::assertSame('$argon2id$new', $user->passwordHash());
        self::assertSame($later, $user->passwordChangedAt());
    }

    public function testMfaIsOffUntilASecretIsStored(): void
    {
        $user = $this->user();

        self::assertFalse($user->hasMfaEnabled());

        $user->enableMfa('encrypted-secret');
        self::assertTrue($user->hasMfaEnabled());

        $user->disableMfa();
        self::assertFalse($user->hasMfaEnabled());
        self::assertNull($user->totpSecretEncrypted());
    }

    public function testTheAclVersionRisesOnEveryBump(): void
    {
        $user = $this->user();
        $before = $user->aclVersion();

        $user->bumpAclVersion();
        $user->bumpAclVersion();

        self::assertSame(
            $before + 2,
            $user->aclVersion(),
            'The version is what invalidates cached permission decisions; it must move on '
            .'every grant change or a revoked permission keeps working (ADR-0011).',
        );
    }

    public function testAnonymisingClearsTheCredentialsAndIsTerminal(): void
    {
        $user = $this->user();
        $user->verifyEmail($this->now());
        $user->enableMfa('encrypted-secret');

        $user->anonymise('erased-1@example.invalid', $this->now());

        self::assertSame('erased-1@example.invalid', $user->email());
        self::assertSame('', $user->passwordHash());
        self::assertNull($user->totpSecretEncrypted());
        self::assertSame(UserStatus::Anonymised, $user->status());
        self::assertFalse(
            $user->status()->canAuthenticate(),
            'An erased account must never authenticate again.',
        );
    }

    private function user(): User
    {
        return new User('someone@example.test', self::PASSWORD_HASH, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }
}

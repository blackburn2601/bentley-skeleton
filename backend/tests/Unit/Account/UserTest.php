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

    public function testANewUserIsActiveAndCanAuthenticate(): void
    {
        $user = $this->user();

        // Workforce model (ADR-0024): accounts are Active on creation. There is no email
        // verification step, so a freshly created account can sign in immediately with the
        // temporary password the administrator was handed.
        self::assertSame(UserStatus::Active, $user->status());
        self::assertTrue($user->status()->canAuthenticate());
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

    public function testChangingTheUsernameDoesNotChangeTheStatus(): void
    {
        $user = $this->user();

        $user->changeUsername('renamed');

        self::assertSame('renamed', $user->username());
        self::assertSame(
            UserStatus::Active,
            $user->status(),
            'Renaming is an administrative edit, not a way to lock someone out by editing their '
            .'profile.',
        );
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

    public function testSuspensionPreventsAuthenticationAndCanBeReinstated(): void
    {
        $user = $this->user();
        $user->suspend();

        self::assertSame(UserStatus::Suspended, $user->status());
        self::assertFalse($user->status()->canAuthenticate());

        $user->reinstate();

        self::assertSame(UserStatus::Active, $user->status());
        self::assertTrue($user->status()->canAuthenticate());
    }

    public function testAnonymisingClearsTheCredentialsAndIsTerminal(): void
    {
        $user = $this->user();

        $user->anonymise('erased-1', $this->now());

        self::assertSame('erased-1', $user->username());
        self::assertSame('', $user->passwordHash());
        self::assertSame(UserStatus::Anonymised, $user->status());
        self::assertFalse(
            $user->status()->canAuthenticate(),
            'An erased account must never authenticate again.',
        );
    }

    private function user(): User
    {
        return new User('someone', self::PASSWORD_HASH, $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }
}

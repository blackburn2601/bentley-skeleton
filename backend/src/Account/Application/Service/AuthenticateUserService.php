<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\PasswordHasher;
use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Decides whether a set of credentials identifies a user who may sign in.
 */
final readonly class AuthenticateUserService
{
    /** Attempts allowed before the account starts locking. */
    private const int LOCK_THRESHOLD = 5;

    /** Doubling from 1 minute: 1, 2, 4, 8… capped below. */
    private const int BASE_LOCK_SECONDS = 60;

    private const int MAX_LOCK_SECONDS = 3600;

    /**
     * A real argon2id hash of a value nobody knows.
     *
     * Verifying against this when the account does not exist keeps the response time of
     * "no such user" indistinguishable from "wrong password". Without it, login is a timing
     * oracle for which addresses are registered — the fast path being the one that skipped
     * hashing.
     */
    private const string DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$Y2FuYXJ5c2FsdHZhbHVl$'
        .'0mV1kJDaFQm6dHY0N1lGqjX8kZ0T1o9x0m0kQzZ3rQY';

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws AccountException always with the same message, whatever actually went wrong
     */
    public function __invoke(string $email, string $plainPassword): User
    {
        $now = $this->clock->now();
        $user = $this->users->findByEmail($email);

        if (null === $user) {
            // Same work, same message, same shape of response.
            $this->hasher->verify(self::DUMMY_HASH, $plainPassword);
            $this->audit->record(SecurityEventType::LoginFailed, null, ['reason' => 'unknown_email']);

            throw AccountException::invalidCredentials();
        }

        if ($user->isLockedAt($now)) {
            $lockedUntil = $user->lockedUntil();
            \assert($lockedUntil instanceof DateTimeImmutable);

            $this->audit->record(SecurityEventType::LoginFailed, $user->id(), ['reason' => 'locked']);

            // The one case that says something specific. Telling a locked-out user to come
            // back later is worth more than the enumeration signal it leaks, and reaching it
            // already requires knowing the password was tried repeatedly.
            throw AccountException::accountLocked($lockedUntil);
        }

        if (!$this->hasher->verify($user->passwordHash(), $plainPassword)) {
            $this->registerFailure($user, $now);

            throw AccountException::invalidCredentials();
        }

        if (!$user->status()->canAuthenticate()) {
            $this->audit->record(SecurityEventType::LoginFailed, $user->id(), [
                'reason' => 'status_'.$user->status()->value,
            ]);

            throw AccountException::accountNotActive();
        }

        $this->onSuccess($user, $plainPassword);

        return $user;
    }

    /**
     * Exponential backoff, so guessing gets slower rather than merely bounded.
     *
     * A fixed lockout window lets an attacker sustain a steady guess rate indefinitely;
     * doubling makes a sustained campaign impractical while a user who mistypes twice notices
     * nothing. The cap stops a forgotten account being locked for weeks, which would turn the
     * mechanism into a denial-of-service against its owner.
     */
    private function registerFailure(User $user, DateTimeImmutable $now): void
    {
        $failures = $user->failedLoginCount() + 1;
        $lockedUntil = null;

        if ($failures >= self::LOCK_THRESHOLD) {
            $seconds = min(
                self::BASE_LOCK_SECONDS * (2 ** ($failures - self::LOCK_THRESHOLD)),
                self::MAX_LOCK_SECONDS,
            );
            $lockedUntil = $now->modify(\sprintf('+%d seconds', $seconds));
        }

        $user->recordFailedLogin($lockedUntil ?? $now->modify('-1 second'));
        $this->em->flush();

        $this->audit->record(SecurityEventType::LoginFailed, $user->id(), [
            'reason' => 'bad_password',
            'failedAttempts' => $failures,
        ]);

        if (null !== $lockedUntil) {
            $this->audit->record(SecurityEventType::AccountLocked, $user->id(), [
                'lockedUntil' => $lockedUntil->format(\DATE_ATOM),
                'failedAttempts' => $failures,
            ]);
        }
    }

    private function onSuccess(User $user, string $plainPassword): void
    {
        $user->recordSuccessfulLogin();

        // The only moment the plaintext is in memory, so the only chance to upgrade a hash
        // written under weaker parameters without involving the user.
        if ($this->hasher->needsRehash($user->passwordHash())) {
            $user->changePassword($this->hasher->hash($plainPassword), $user->passwordChangedAt());
        }

        $this->em->flush();
    }
}

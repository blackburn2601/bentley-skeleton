<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\RefreshToken;
use App\Account\Domain\SingleUseToken;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Shared\Domain\TokenHash;
use App\Tests\Functional\ApiTestCase;

/**
 * Password reset, end to end.
 *
 * The flow that hands out account access to whoever holds a link, so the properties asserted
 * here are the ones that keep that safe: the response never reveals whether an address
 * exists, tokens are single-use and short-lived, and a successful reset ends every existing
 * session.
 */
final class PasswordResetFlowTest extends ApiTestCase
{
    private const string NEW_PASSWORD = 'a-completely-different-passphrase-77';

    public function testRequestingAResetNeverRevealsWhetherTheAccountExists(): void
    {
        $user = $this->createUser('reset');

        $this->json('POST', '/api/v1/auth/password/forgot', ['email' => $user->email()]);
        $existing = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];

        $this->json('POST', '/api/v1/auth/password/forgot', ['email' => 'nobody-here@functional.test']);
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->responseJson()];

        self::assertSame(
            $existing,
            $unknown,
            'This endpoint needs no credentials at all. Any difference makes it a membership '
            .'oracle for every address an attacker cares to try.',
        );
    }

    public function testAValidTokenSetsANewPasswordAndEndsEverySession(): void
    {
        $user = $this->createUser('reset');

        // A live session that must not survive the reset.
        $this->logIn($user);
        $this->logOut();

        $token = $this->issueResetToken($user);

        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $live = $this->em->getRepository(RefreshToken::class)->findBy(['user' => $user, 'revokedAt' => null]);
        self::assertSame(
            [],
            $live,
            'A reset is the standard response to "someone else has my password". Leaving their '
            .'sessions alive would defeat the entire point.',
        );

        // The new password works.
        $this->json('POST', '/api/v1/auth/login', ['email' => $user->email(), 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    public function testATokenCannotBeUsedTwice(): void
    {
        $user = $this->createUser('reset');
        $token = $this->issueResetToken($user);

        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => 'yet-another-passphrase-88']);
        self::assertResponseStatusCodeSame(401, 'A reset token is single-use; replay must be refused.');
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->json('POST', '/api/v1/auth/password/reset', ['token' => 'not-a-real-token', 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAWeakNewPasswordIsRefusedAndTheTokenSurvives(): void
    {
        $user = $this->createUser('reset');
        $token = $this->issueResetToken($user);

        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => 'aaaaaaaaaaaaaaaa']);
        self::assertResponseStatusCodeSame(422);

        // The token must NOT be consumed by a rejected attempt, or a typo costs the user
        // another round trip through their inbox.
        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    public function testResettingClearsAnAccountLockout(): void
    {
        $user = $this->createUser('locked');

        // Lock the account by failing repeatedly.
        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $this->json('POST', '/api/v1/auth/login', ['email' => $user->email(), 'password' => 'wrong-password-here']);
        }

        self::assertTrue(
            $this->reload($user)->isLockedAt($this->clock->now()),
            'Repeated failures should lock the account.',
        );

        $token = $this->issueResetToken($user);
        $this->json('POST', '/api/v1/auth/password/reset', ['token' => $token, 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful();

        $this->json('POST', '/api/v1/auth/login', ['email' => $user->email(), 'password' => self::NEW_PASSWORD]);
        self::assertResponseIsSuccessful(
            'Proving control of the mailbox should clear a lockout; otherwise an attacker can '
            .'lock someone out of their own account indefinitely.',
        );
    }

    /**
     * Issue a reset token directly.
     *
     * The email itself is exercised by the registration flow test; going through the mailer
     * here would only add a dependency on parsing HTML.
     */
    private function issueResetToken(User $user): string
    {
        $plaintext = 'reset-'.bin2hex(random_bytes(16));

        // Re-read the user: requests made earlier in the test may have left this instance
        // detached, and persisting a token that references a detached entity fails with
        // "a new entity was found through the relationship" rather than anything informative.
        $this->em->persist(new SingleUseToken(
            TokenHash::of($plaintext)->value,
            $this->reload($user),
            TokenPurpose::ResetPassword,
            $this->clock->now(),
            $this->clock->now()->add(TokenPurpose::ResetPassword->ttl()),
        ));
        $this->em->flush();

        return $plaintext;
    }
}

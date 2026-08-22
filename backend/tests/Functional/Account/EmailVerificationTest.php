<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Application\Service\IssueSingleUseTokenService;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Shared\Domain\TokenHash;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use ReflectionProperty;

/**
 * POST /api/v1/auth/verify-email.
 *
 * The fixtures verify accounts by calling the entity directly, which is fast and right for
 * tests about something else — but it meant the endpoint, the service and the single-use
 * token rules had no coverage at all, in a flow whose entire job is to decide whether someone
 * controls an address.
 *
 * These go through HTTP, and they concentrate on the ways a token must NOT work: reused,
 * expired, wrong purpose, unknown. The happy path is one case; the refusals are the feature.
 */
final class EmailVerificationTest extends ApiTestCase
{
    public function testAValidTokenVerifiesTheAddress(): void
    {
        $user = $this->createUser('verify', verified: false);
        self::assertFalse($user->isEmailVerified(), 'Precondition.');

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => $this->issue($user)]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($user)->isEmailVerified());
    }

    public function testTheSameTokenCannotBeUsedTwice(): void
    {
        $user = $this->createUser('verify-twice', verified: false);
        $token = $this->issue($user);

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => $token]);
        self::assertResponseIsSuccessful();

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => $token]);

        // A verification link sits in an inbox forever. If replaying it kept working, anyone
        // who later reads that mailbox could re-confirm an address the owner has since
        // changed.
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->createUser('verify-unknown', verified: false);

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => bin2hex(random_bytes(32))]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenIssuedForADifferentPurposeIsRefused(): void
    {
        $user = $this->createUser('verify-purpose', verified: false);
        $reset = $this->issue($user, TokenPurpose::ResetPassword);

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => $reset]);

        // Purpose is checked, not just validity. Otherwise a password-reset token — which is
        // handed out to anyone who can name an address — would double as proof of control
        // over that address.
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->reload($user)->isEmailVerified());
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $user = $this->createUser('verify-expired', verified: false);
        $plaintext = $this->issue($user);

        $tokens = self::getContainer()->get(SingleUseTokenRepository::class);
        $token = $tokens->findByHash(TokenHash::of($plaintext)->value);
        self::assertNotNull($token);

        $this->expire($token);

        $this->json('POST', '/api/v1/auth/verify-email', ['token' => $plaintext]);

        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->reload($user)->isEmailVerified());
    }

    public function testEveryRefusalLooksIdenticalWhicheverWayTheTokenIsBad(): void
    {
        $user = $this->createUser('verify-quiet', verified: false);

        $expired = $this->issue($user);
        $tokens = self::getContainer()->get(SingleUseTokenRepository::class);
        $found = $tokens->findByHash(TokenHash::of($expired)->value);
        self::assertNotNull($found);
        $this->expire($found);

        $responses = [];

        foreach ([
            'unknown' => bin2hex(random_bytes(32)),
            'expired' => $expired,
            'wrong purpose' => $this->issue($user, TokenPurpose::ResetPassword),
        ] as $label => $token) {
            $this->json('POST', '/api/v1/auth/verify-email', ['token' => $token]);

            self::assertResponseHeaderSame('content-type', 'application/problem+json');

            $body = $this->responseJson();
            // The request id is per-request by design and says nothing about the token.
            unset($body['requestId']);
            $responses[$label] = $body;
        }

        // The message says "invalid or has expired" for all three on purpose. If the wording
        // or the status differed, the endpoint would answer "does this token exist?" and
        // "what was it for?" to anyone willing to ask repeatedly.
        self::assertSame($responses['unknown'], $responses['expired']);
        self::assertSame($responses['unknown'], $responses['wrong purpose']);
    }

    private function issue(User $user, TokenPurpose $purpose = TokenPurpose::VerifyEmail): string
    {
        $issue = self::getContainer()->get(IssueSingleUseTokenService::class);
        $token = $issue($user, $purpose);
        $this->em->flush();

        return $token;
    }

    private function expire(object $token): void
    {
        // The expiry is set at issue time and has no setter — reaching past that is the point
        // of the test, and doing it here keeps the production class free of a "for tests only"
        // mutator that something else would eventually call.
        $property = new ReflectionProperty($token, 'expiresAt');
        $property->setValue($token, new DateTimeImmutable('-1 hour'));
        $this->em->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Api\Security\AuthCookies;
use App\Shared\Infrastructure\Security\SodiumSecretBox;
use App\Tests\Functional\ApiTestCase;
use OTPHP\TOTP;
use ReflectionProperty;

/**
 * The TOTP second-factor flow, through the real kernel (ADR-0026).
 *
 * Mirrors {@see AuthenticationFlowTest}: these are the properties a client depends on and the
 * security properties that fail silently when someone "improves" an error path — the
 * half-authenticated state above all, which is the kind of thing that reads as ordinary
 * middleware and stops being enforced the moment a voter is reordered.
 */
final class TwoFactorFlowTest extends ApiTestCase
{
    private const string ADMIN_ROLE = 'ROLE_TEST_MFA_ADMIN';

    public function testEnrolProvisionalSecretThenConfirmWithAValidCode(): void
    {
        $user = $this->createUser('enrol');
        $this->logIn($user);

        $this->json('POST', '/api/v1/account/mfa/enrol', [], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        $enrolment = $this->responseJson();

        self::assertIsString($enrolment['secret']);

        $provisioningUri = $enrolment['provisioningUri'] ?? '';
        self::assertIsString($provisioningUri);
        self::assertStringStartsWith('otpauth://totp/', $provisioningUri);

        $qrDataUrl = $enrolment['qrDataUrl'] ?? '';
        self::assertIsString($qrDataUrl);
        self::assertStringStartsWith('data:image/', $qrDataUrl);

        $this->json('POST', '/api/v1/account/mfa/confirm', ['code' => $this->totpCode($enrolment['secret'])], $this->csrfHeader());
        self::assertResponseIsSuccessful();

        $codes = $this->responseJson()['recoveryCodes'] ?? null;
        self::assertIsArray($codes);
        self::assertCount(10, $codes, 'Confirm mints exactly ten single-use recovery codes.');

        // The provisional secret is now live; the row is committed.
        $reloaded = $this->reload($user);
        self::assertTrue($reloaded->hasEnrolledTotp());
        self::assertNull($reloaded->totpSecretEncryptedProvisional());
    }

    public function testLoginOfAnEnrolledUserIssuesAPendingChallengeAndNoRefreshCookie(): void
    {
        $user = $this->createUser('pending');
        $this->enrolAndConfirm($user);
        $this->logOut();

        $this->json('POST', '/api/v1/auth/login', [
            'username' => $user->username(),
            'password' => self::PASSWORD,
        ]);
        self::assertResponseIsSuccessful();

        $body = $this->responseJson();
        self::assertSame('pending', $body['mfaRequired'] ?? null, 'An enrolled user logs into a pending state, not a full session.');
        self::assertSame([], $body['roles'] ?? null, 'No roles are exposed while the second factor is still owed.');

        // Only the access cookie is set. No refresh cookie before MFA verifies — a stolen refresh
        // cookie would otherwise bypass the second factor for its whole 30-day lifetime (ADR-0026).
        $setCookies = $this->setCookieList();
        self::assertNotNull($this->setCookieFor($setCookies, AuthCookies::ACCESS), 'The challenge rides in the access-cookie slot.');
        self::assertNull($this->setCookieFor($setCookies, AuthCookies::REFRESH), 'No refresh cookie is issued before the second factor verifies.');
        self::assertNull($this->setCookieFor($setCookies, AuthCookies::CSRF), 'No CSRF cookie is issued before the second factor verifies.');

        $access = (string) $this->setCookieFor($setCookies, AuthCookies::ACCESS);
        self::assertStringContainsString('httponly', strtolower($access));
        self::assertStringContainsString('secure', strtolower($access));
        self::assertStringContainsString('samesite=strict', strtolower($access));

        self::assertStringNotContainsString('eyJ', (string) $this->client->getResponse()->getContent(), 'Tokens never travel in the body.');
    }

    public function testVerifyWithAValidCodeCompletesTheSession(): void
    {
        $user = $this->createUser('verify');
        ['secret' => $secret] = $this->enrolAndConfirm($user);
        $this->logOut();

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => $this->totpCode($secret)]);
        self::assertResponseIsSuccessful();

        $body = $this->responseJson();
        self::assertSame($user->id()->toRfc4122(), $body['id'] ?? null);
        self::assertSame('verified', $body['mfaRequired'] ?? null, 'A completed second factor reads as verified, not pending.');

        // The refresh and CSRF cookies arrive only now that the session is fully authenticated.
        $setCookies = $this->setCookieList();
        self::assertNotNull($this->setCookieFor($setCookies, AuthCookies::REFRESH));
        self::assertNotNull($this->setCookieFor($setCookies, AuthCookies::CSRF));

        // And the caller can now reach an ordinary authenticated endpoint.
        $this->json('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testVerifyWithAWrongCodeIsRefusedWithAProblemDetail(): void
    {
        $user = $this->createUser('wrong');
        ['secret' => $secret] = $this->enrolAndConfirm($user);
        $this->logOut();

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        $good = $this->totpCode($secret);
        $wrong = '000000' === $good ? '111111' : '000000';

        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => $wrong]);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(
            'The authentication code is incorrect or has expired.',
            $this->responseJson()['detail'] ?? null,
        );
    }

    public function testVerifyWithAnUndecryptableSecretIsRefusedAs401Not500(): void
    {
        // Simulates a TOTP_SECRET_KEY rotation after enrolment: the stored ciphertext was
        // encrypted under a key the decryptor no longer holds, so sodium_crypto_secretbox_open
        // returns false and VerifyTwoFactorService hits SecretDecryptionFailed. That must
        // surface as the identical 401 — the same anti-enumeration invariant as a wrong code —
        // never a 500. A 500 would both leak a server-side condition to an unauthenticated
        // caller and lock the user out with a crash instead of a clean refusal.
        $user = $this->createUser('rotated');
        $this->enrolAndConfirm($user);
        $this->logOut();

        // Replace the live ciphertext with one encrypted under a foreign 32-byte key.
        $foreignCipher = (new SodiumSecretBox(base64_encode(random_bytes(32))))->encrypt('not-the-real-secret');
        $fresh = $this->em->find(User::class, $user->id());
        \assert($fresh instanceof User);
        $this->em->refresh($fresh);
        (new ReflectionProperty(User::class, 'totpSecretEncrypted'))->setValue($fresh, $foreignCipher);
        $this->em->flush();

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => '000000']);
        self::assertResponseStatusCodeSame(401, 'An undecryptable secret must look like a wrong code, not a server error.');
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(
            'The authentication code is incorrect or has expired.',
            $this->responseJson()['detail'] ?? null,
        );
    }

    public function testAVerifiedCallerCannotReachTheVerifyEndpoint(): void
    {
        // A fully authenticated (non-pending) caller must not re-enter the verify path.
        $this->logIn($this->createUser('verified'));

        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => '123456'], $this->csrfHeader());
        self::assertResponseStatusCodeSame(403, 'A verified session is not MFA_PENDING; the MfaStageVoter must deny it.');
    }

    public function testAPendingCallerIsDeniedOrdinaryEndpoints(): void
    {
        $user = $this->createUser('locked');
        $this->enrolAndConfirm($user);
        $this->logOut();

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        // The pending access token carries roles:[] and the MfaStageVoter denies everything but
        // MFA_PENDING, so a perfectly ordinary authenticated endpoint is unreachable.
        $this->json('GET', '/api/v1/auth/me');
        self::assertResponseStatusCodeSame(403);
    }

    public function testARecoveryCodeCompletesTheSessionAndIsSingleUse(): void
    {
        $user = $this->createUser('recovery');
        ['recoveryCodes' => $codes] = $this->enrolAndConfirm($user);
        $this->logOut();

        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        $this->json('POST', '/api/v1/auth/mfa/recovery/verify', ['code' => $codes[0]]);
        self::assertResponseIsSuccessful('A valid recovery code completes the session.');
        self::assertSame('verified', $this->responseJson()['mfaRequired'] ?? null);

        // The same code cannot be spent twice. Re-login to get a fresh challenge, then replay it.
        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertSame('pending', $this->responseJson()['mfaRequired'] ?? null);

        $this->json('POST', '/api/v1/auth/mfa/recovery/verify', ['code' => $codes[0]]);
        self::assertResponseStatusCodeSame(401, 'A burned recovery code must be refused on its second use.');
    }

    public function testAdminRequiringMfaOnAnUnenrolledUserBlocksTheirLogin(): void
    {
        $target = $this->createUser('required');
        $this->logIn($this->admin());

        $this->json('PUT', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/required', ['required' => true], $this->csrfHeader());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->responseJson()['mfaRequired'] ?? false);

        $this->logOut();

        // Correct password, but required-and-unenrolled: the login is refused with a Forbidden
        // problem, not force-enrolled at the prompt. Reached only after the password checked
        // out, so it leaks nothing about which usernames exist.
        $this->json('POST', '/api/v1/auth/login', ['username' => $target->username(), 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(
            'Multi-factor authentication is required for this account but has not been set up. Contact an administrator.',
            $this->responseJson()['detail'] ?? null,
        );
    }

    public function testSelfDisableRemovesTheFactorAndRestoresPasswordOnlyLogin(): void
    {
        $user = $this->createUser('disable');
        $this->enrolAndConfirm($user);

        // The access token from the pre-MFA login is still valid for its short life, so the
        // caller can disable the factor in the same session that enrolled it.
        $this->json('DELETE', '/api/v1/account/mfa', [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(204);

        $reloaded = $this->reload($user);
        self::assertFalse($reloaded->hasEnrolledTotp());

        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', ['username' => $user->username(), 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->responseJson()['mfaRequired'] ?? null, 'With no factor and no requirement, login is a full session again.');
    }

    public function testAdminResetClearsTheFactorAndTheRequirement(): void
    {
        $target = $this->createUser('reset');
        $this->enrolAndConfirm($target);
        $this->logOut();

        $this->logIn($this->admin());
        $this->json('PUT', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/required', ['required' => true], $this->csrfHeader());
        self::assertTrue($this->responseJson()['mfaRequired'] ?? false);

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/reset', [], $this->csrfHeader());
        self::assertResponseStatusCodeSame(204);

        $reloaded = $this->reload($target);
        self::assertFalse($reloaded->hasEnrolledTotp());
        self::assertFalse($reloaded->isMfaRequired());

        $this->logOut();
        $this->json('POST', '/api/v1/auth/login', ['username' => $target->username(), 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful('After a reset the user is back on the no-MFA floor.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Enrol and confirm a factor for a user, returning the plaintext secret and recovery codes.
     *
     * Logs in as the user (enrol/confirm need account.update) and leaves the caller in that
     * authenticated session; the access token from this login is still valid for its short life,
     * which is what lets a self-disable test chain off the same session.
     *
     * @return array{secret: string, recoveryCodes: array<array-key, mixed>}
     */
    private function enrolAndConfirm(User $user): array
    {
        $this->logIn($user);

        $this->json('POST', '/api/v1/account/mfa/enrol', [], $this->csrfHeader());
        self::assertResponseIsSuccessful('Enrol should succeed for an authenticated owner.');
        $secret = $this->responseJson()['secret'] ?? '';
        self::assertIsString($secret);

        $this->json('POST', '/api/v1/account/mfa/confirm', ['code' => $this->totpCode($secret)], $this->csrfHeader());
        self::assertResponseIsSuccessful('Confirm with the current code should activate the factor.');

        return ['secret' => $secret, 'recoveryCodes' => (array) ($this->responseJson()['recoveryCodes'] ?? [])];
    }

    private function totpCode(string $secret): string
    {
        \assert('' !== $secret);

        return TOTP::createFromSecret($secret)->now();
    }

    private function admin(): User
    {
        $caller = $this->createUser('admin');
        $this->assignRole($caller, self::ADMIN_ROLE);
        $this->grantRolePermission(self::ADMIN_ROLE, PermissionCatalog::USER_UPDATE);

        return $caller;
    }

    // ------------------------------------------------------------------ cookies

    /**
     * @return list<string>
     */
    private function setCookieList(): array
    {
        return array_values(array_filter(
            $this->client->getResponse()->headers->all('set-cookie'),
            static fn (?string $header): bool => null !== $header,
        ));
    }

    /**
     * @param list<string> $setCookies
     */
    private function setCookieFor(array $setCookies, string $name): ?string
    {
        foreach ($setCookies as $header) {
            if (str_starts_with($header, $name.'=')) {
                return $header;
            }
        }

        return null;
    }
}

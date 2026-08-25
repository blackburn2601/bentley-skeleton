<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/auth/mfa/verify — the TOTP second-factor completion (ADR-0026).
 *
 * The happy path and the security properties (cookie shape, anti-enumeration, pending-stage
 * denial) live in {@see TwoFactorFlowTest}. These are the per-endpoint access-control checks.
 */
final class VerifyTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => '123456']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsAVerifiedCallerWhoIsNotPending(): void
    {
        // A fully authenticated session is not MFA_PENDING. The CSRF header is sent so the refusal
        // is the MfaStageVoter's deny, not a CSRF mismatch — the two are different 403s and only
        // the former is what this endpoint is responsible for enforcing.
        $this->logIn($this->createUser('verified'));

        $this->json('POST', '/api/v1/auth/mfa/verify', ['code' => '123456'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }
}

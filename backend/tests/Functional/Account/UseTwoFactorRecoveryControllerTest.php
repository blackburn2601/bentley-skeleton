<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/auth/mfa/recovery/verify — the recovery-code fallback (ADR-0026).
 *
 * The happy path and single-use semantics live in {@see TwoFactorFlowTest}; these are the
 * per-endpoint access-control checks, mirroring {@see VerifyTwoFactorControllerTest}.
 */
final class UseTwoFactorRecoveryControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('POST', '/api/v1/auth/mfa/recovery/verify', ['code' => '1234567890']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsAVerifiedCallerWhoIsNotPending(): void
    {
        $this->logIn($this->createUser('verified'));

        $this->json('POST', '/api/v1/auth/mfa/recovery/verify', ['code' => '1234567890'], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }
}

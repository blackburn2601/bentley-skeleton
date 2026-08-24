<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * POST /api/v1/admin/users/{id}/mfa/reset — admin strips a user's factor (ADR-0026).
 *
 * The reset flow lives in {@see TwoFactorFlowTest}; these are the per-endpoint access-control
 * checks, mirroring {@see AdminRequireTwoFactorControllerTest}.
 */
final class AdminResetTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $target = $this->createUser('target');

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/reset');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutUserUpdate(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->createUser('bystander'));

        $this->json('POST', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/reset', [], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }
}

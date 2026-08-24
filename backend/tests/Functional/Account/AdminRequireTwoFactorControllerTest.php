<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * PUT /api/v1/admin/users/{id}/mfa/required — admin enforces or lifts the MFA requirement (ADR-0026).
 *
 * The require flow and the required-but-unenrolled login block live in {@see TwoFactorFlowTest};
 * these are the per-endpoint access-control checks. The endpoint needs `user.update`, an
 * administrative permission the baseline role does not carry, so a plain registered user is
 * the refused caller.
 */
final class AdminRequireTwoFactorControllerTest extends ApiTestCase
{
    public function testItRejectsAnAnonymousCaller(): void
    {
        $target = $this->createUser('target');

        $this->json('PUT', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/required', ['required' => true]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutUserUpdate(): void
    {
        $target = $this->createUser('target');
        $this->logIn($this->createUser('bystander'));

        $this->json('PUT', '/api/v1/admin/users/'.$target->id()->toRfc4122().'/mfa/required', ['required' => true], $this->csrfHeader());

        self::assertResponseStatusCodeSame(403);
    }
}

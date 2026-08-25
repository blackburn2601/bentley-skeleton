<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Account\Domain\User;
use App\Acl\Domain\PermissionCatalog;
use App\Audit\Domain\SecurityEvent;
use App\Shared\Domain\SecurityEventType;
use App\Tests\Functional\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/admin/audit-events.
 */
final class ListSecurityEventsControllerTest extends ApiTestCase
{
    private const string ROLE = 'ROLE_TEST_AUDIT_READER';

    public function testItRejectsAnAnonymousCaller(): void
    {
        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseStatusCodeSame(401);
    }

    public function testItRejectsACallerWithoutThePermission(): void
    {
        $this->logIn($this->createUser('nobody'));

        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItReturnsTheEnvelopeForAPermittedCaller(): void
    {
        $this->logIn($this->permittedCaller());

        $this->json('GET', '/api/v1/admin/audit-events');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        // Every collection returns the same four keys, paged or not (ADR-0019).
        self::assertCount($body['total'], $body['items']);
        self::assertGreaterThanOrEqual(1, $body['page']);
    }

    public function testItMatchesTheSearchTermAgainstTheEventType(): void
    {
        $this->logIn($this->permittedCaller());

        // "login" is exactly the term the bug report used. It must hit both login events by
        // substring and must NOT pull in permission_granted, even though a login_succeeded
        // event is also recorded by the real login endpoint above — so the assertions are on
        // the shape (every hit mentions "login", both login types present) rather than a total.
        $this->recordEvent(SecurityEventType::LoginSucceeded, '-5 minutes');
        $this->recordEvent(SecurityEventType::LoginFailed, '-4 minutes');
        $this->recordEvent(SecurityEventType::PermissionGranted, '-3 minutes');

        $this->json('GET', '/api/v1/admin/audit-events?q=login');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        $types = $this->column($body['items'], 'type');

        self::assertContains('login_succeeded', $types);
        self::assertContains('login_failed', $types);
        // No event whose type does not mention "login" leaks through the filter.
        self::assertNotContains('permission_granted', $types);
        foreach ($types as $type) {
            self::assertStringContainsString('login', $type);
        }
    }

    public function testItMatchesTheSearchTermAgainstIpAddressAndRequestId(): void
    {
        $this->logIn($this->permittedCaller());

        // "0.113" appears in no enum wire value, no uuid (hex + hyphens, no dots) and no
        // generated request id, so it can only be hit by the IP we set explicitly.
        $this->recordEvent(SecurityEventType::PasswordChanged, '-2 minutes', '203.0.113.9');
        $this->recordEvent(SecurityEventType::LogoutSucceeded, '-1 minute', '198.51.100.7');

        $this->json('GET', '/api/v1/admin/audit-events?q=0.113');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['items']);
        self::assertSame('password_changed', $body['items'][0]['type']);
    }

    public function testItReturnsNothingForATermThatMatchesNoEvent(): void
    {
        $this->logIn($this->permittedCaller());

        $this->recordEvent(SecurityEventType::LoginSucceeded, '-1 minute');

        $this->json('GET', '/api/v1/admin/audit-events?q=zzz-nope-nothing-here');

        self::assertResponseIsSuccessful();
        $body = $this->pageJson();

        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
    }

    /**
     * Persist a security event with a deterministic timestamp, no actor and no request id by
     * default — the search tests care about type/ip/requestId matches, not actor matching.
     */
    private function recordEvent(
        SecurityEventType $type,
        string $occurredAtModifier,
        ?string $ipAddress = null,
        ?string $requestId = null,
        ?Uuid $actorId = null,
    ): void {
        $event = new SecurityEvent(
            $type,
            new DateTimeImmutable($occurredAtModifier),
            $actorId,
            $ipAddress,
            null,
            $requestId,
            [],
        );

        $this->em->persist($event);
        $this->em->flush();
    }

    private function permittedCaller(): User
    {
        $caller = $this->createUser('audit-reader');
        $this->assignRole($caller, self::ROLE);
        $this->grantRolePermission(self::ROLE, PermissionCatalog::AUDIT_READ);

        return $caller;
    }
}

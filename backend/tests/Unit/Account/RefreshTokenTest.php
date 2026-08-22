<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\Domain\RefreshToken;
use App\Account\Domain\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The refresh token's usability rules — the mechanics reuse detection depends on.
 */
#[CoversClass(RefreshToken::class)]
final class RefreshTokenTest extends TestCase
{
    public function testAFreshTokenStartsItsOwnFamily(): void
    {
        $token = $this->token();

        self::assertTrue(
            $token->familyId()->equals($token->id()),
            'A login with no parent begins a new family, so revoking a compromised chain does '
            .'not sign the user out of their other devices.',
        );
    }

    public function testASuccessorInheritsTheFamily(): void
    {
        $parent = $this->token();
        $child = $this->token(familyId: $parent->familyId(), parentId: $parent->id());

        self::assertTrue($child->familyId()->equals($parent->familyId()));
        self::assertFalse($child->id()->equals($parent->id()));
    }

    public function testATokenIsUsableExactlyOnce(): void
    {
        $token = $this->token();

        self::assertTrue($token->isUsableAt($this->now()));

        $token->markUsed($this->now(), Uuid::v7());

        self::assertTrue($token->isUsed());
        self::assertFalse(
            $token->isUsableAt($this->now()),
            'A second use is the reuse signal; it must never be accepted as an ordinary refresh.',
        );
    }

    public function testRevokingMakesATokenUnusable(): void
    {
        $token = $this->token();
        $token->revoke($this->now());

        self::assertTrue($token->isRevoked());
        self::assertFalse($token->isUsableAt($this->now()));
    }

    public function testRevokingTwiceKeepsTheFirstTimestamp(): void
    {
        $token = $this->token();
        $first = $this->now();
        $token->revoke($first);
        $token->revoke($first->modify('+1 hour'));

        self::assertTrue($token->isRevoked());
    }

    public function testAnExpiredTokenIsUnusable(): void
    {
        $token = $this->token(expiresAt: $this->now()->modify('-1 second'));

        self::assertTrue($token->isExpiredAt($this->now()));
        self::assertFalse($token->isUsableAt($this->now()));
    }

    public function testAnOverlongUserAgentIsTruncatedRatherThanRejected(): void
    {
        $token = $this->token(userAgent: str_repeat('a', 400));

        self::assertSame(255, mb_strlen((string) $token->userAgent()));
    }

    private function token(
        ?Uuid $familyId = null,
        ?Uuid $parentId = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $userAgent = null,
    ): RefreshToken {
        return new RefreshToken(
            tokenHash: hash('sha256', bin2hex(random_bytes(8))),
            user: new User('token@example.test', 'hash', $this->now()),
            createdAt: $this->now(),
            expiresAt: $expiresAt ?? $this->now()->modify('+30 days'),
            familyId: $familyId,
            parentId: $parentId,
            ipAddress: '203.0.113.1',
            userAgent: $userAgent,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T12:00:00+00:00');
    }
}

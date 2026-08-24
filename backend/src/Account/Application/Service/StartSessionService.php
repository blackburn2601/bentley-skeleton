<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\RefreshToken;
use App\Account\Domain\RefreshTokenRepository;
use App\Account\Domain\User;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Opens a new refresh-token family for a user who has just authenticated.
 */
final readonly class StartSessionService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private SecretGenerator $secrets,
        private Clock $clock,
        private EntityManagerInterface $em,
        private int $ttlSeconds = 2_592_000,
    ) {
    }

    /**
     * A fresh login starts its own family, so revoking a compromised chain never signs the
     * user out of their other devices.
     *
     * @param list<string> $amr how the session authenticated (ADR-0026); ['totp'] after MFA
     *
     * @return array{token: RefreshToken, plaintext: string}
     */
    public function __invoke(User $user, ?string $ipAddress, ?string $userAgent, array $amr = []): array
    {
        $now = $this->clock->now();
        $plaintext = $this->secrets->generate();

        $token = new RefreshToken(
            tokenHash: TokenHash::of($plaintext)->value,
            user: $user,
            createdAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $this->ttlSeconds)),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            amr: $amr,
        );

        $this->tokens->save($token);
        $this->em->flush();

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}

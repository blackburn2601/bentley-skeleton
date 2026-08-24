<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\RefreshToken;
use App\Account\Domain\RefreshTokenRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\SecurityEventType;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Exchanges a refresh token for its successor, detecting reuse.
 */
final readonly class RotateRefreshTokenService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private SecretGenerator $secrets,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
        private int $ttlSeconds = 2_592_000,
    ) {
    }

    /**
     * @return array{token: RefreshToken, plaintext: string} the successor, and its plaintext
     *
     * @throws AccountException on an unknown, expired, revoked or already-used token
     */
    public function __invoke(string $presentedPlaintext, ?string $ipAddress, ?string $userAgent): array
    {
        $now = $this->clock->now();
        $presented = $this->tokens->findByHash(TokenHash::of($presentedPlaintext)->value);

        if (null === $presented) {
            throw AccountException::invalidToken();
        }

        // The security-critical branch. A token that has ALREADY been rotated is being
        // presented a second time, which means one of:
        //
        //   - it was stolen, used by the attacker, and the legitimate client has just caught
        //     up (or the reverse); or
        //   - the client refreshed twice concurrently — which is why the SPA does
        //     single-flight refresh.
        //
        // We cannot distinguish the two, so we assume the worse one and revoke the entire
        // family. Both parties are logged out, which is recoverable; leaving a live stolen
        // token in circulation is not.
        if ($presented->isUsed()) {
            $revoked = $this->tokens->revokeFamily($presented->familyId(), $now);
            $this->em->flush();

            $this->audit->record(SecurityEventType::RefreshTokenReuse, $presented->user()->id(), [
                'familyId' => $presented->familyId()->toRfc4122(),
                'tokensRevoked' => $revoked,
                'ipAddress' => $ipAddress,
            ]);

            throw AccountException::invalidToken();
        }

        if (!$presented->isUsableAt($now)) {
            throw AccountException::invalidToken();
        }

        $plaintext = $this->secrets->generate();

        $successor = new RefreshToken(
            tokenHash: TokenHash::of($plaintext)->value,
            user: $presented->user(),
            createdAt: $now,
            expiresAt: $now->modify(\sprintf('+%d seconds', $this->ttlSeconds)),
            // Same family: rotation extends the chain rather than starting a new one, which
            // is what lets a reuse anywhere in it revoke the whole thing.
            familyId: $presented->familyId(),
            parentId: $presented->id(),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            // The family's authentication method travels with it, so a refresh reissues an
            // access token carrying the same amr claim (ADR-0026).
            amr: $presented->amr(),
        );

        $this->tokens->save($successor);
        $presented->markUsed($now, $successor->id());
        $this->em->flush();

        $this->audit->record(SecurityEventType::RefreshTokenRotated, $presented->user()->id(), [
            'familyId' => $presented->familyId()->toRfc4122(),
        ]);

        return ['token' => $successor, 'plaintext' => $plaintext];
    }
}

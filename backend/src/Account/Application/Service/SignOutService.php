<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\RefreshTokenRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Ends the session belonging to a presented refresh token.
 */
final readonly class SignOutService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Never throws. An unknown or already-dead token still means "this client wants out", and
     * the caller's cookies get cleared either way.
     *
     * Revokes the whole family rather than the single token: the family IS the session, and
     * leaving its successors live would mean logging out did not.
     */
    public function __invoke(?string $presentedToken): void
    {
        if (null === $presentedToken || '' === $presentedToken) {
            return;
        }

        $token = $this->tokens->findByHash(TokenHash::of($presentedToken)->value);

        if (null === $token) {
            return;
        }

        $this->tokens->revokeFamily($token->familyId(), $this->clock->now());
        $this->em->flush();

        $this->audit->record(SecurityEventType::LogoutSucceeded, $token->user()->id(), [
            'familyId' => $token->familyId()->toRfc4122(),
        ]);
    }
}

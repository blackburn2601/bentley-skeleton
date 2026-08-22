<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\TokenPurpose;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecurityEventType;
use App\Shared\Domain\TokenHash;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @responsibility Confirms that a user controls the email address they registered.
 */
final readonly class VerifyEmailService
{
    public function __construct(
        private SingleUseTokenRepository $tokens,
        private AuditFacade $audit,
        private Clock $clock,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $plaintextToken): void
    {
        $now = $this->clock->now();
        $token = $this->tokens->findByHash(TokenHash::of($plaintextToken)->value);

        if (null === $token || !$token->isUsableAt($now, TokenPurpose::VerifyEmail)) {
            throw AccountException::invalidToken();
        }

        $this->em->wrapInTransaction(function () use ($token, $now): void {
            $token->consume($now);
            $token->user()->verifyEmail($now);
            $this->em->flush();
        });

        $this->audit->record(SecurityEventType::EmailVerified, $token->user()->id());
    }
}

<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Sets or clears the MFA requirement an administrator enforces on one user.
 *
 * The requirement is a login-time policy, not a permission, so it takes effect at the next
 * sign-in: a user with `mfaRequired` but no enrolled factor is refused with
 * `mfa_required_not_enrolled` rather than force-enrolled at the prompt (a phishing primitive).
 * Existing sessions are not revoked — they already authenticated — and `aclVersion` is not
 * bumped, because MFA state is not a permission and bumping would mislabel a no-op cache change.
 */
final readonly class AdminRequireTwoFactorService
{
    public function __construct(
        private UserRepository $users,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, bool $required, Uuid $changedBy): User
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        if ($required === $user->isMfaRequired()) {
            return $user;
        }

        $type = $required ? SecurityEventType::MfaRequiredSet : SecurityEventType::MfaRequiredCleared;

        if ($required) {
            $user->requireMfa();
        } else {
            $user->clearMfaRequirement();
        }

        $this->em->flush();

        $this->audit->record($type, $changedBy, ['subjectId' => $user->id()->toRfc4122()]);

        return $user;
    }
}

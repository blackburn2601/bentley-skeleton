<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Applies an administrator's edits to one user's email address.
 *
 * The address is the account's identity, so changing it is not a cosmetic edit: it redirects
 * every future password reset. Two consequences follow, and both are deliberate.
 *
 * The new address is marked unverified, because nobody has shown they can receive mail there —
 * a typo would otherwise become a "verified" address that resets are sent to. And a
 * verification mail goes out immediately, so the change is visible to whoever now owns the
 * address rather than only in an audit row nobody reads.
 */
final readonly class UpdateUserService
{
    public function __construct(
        private UserRepository $users,
        private IssueSingleUseTokenService $issueToken,
        private SendAccountEmailService $email,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $userId, string $email, Uuid $changedBy): User
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        $email = mb_strtolower(trim($email));

        if ($email === $user->email()) {
            return $user;
        }

        if ($this->users->existsByEmail($email)) {
            throw AccountException::emailAlreadyInUse($email);
        }

        $previous = $user->email();
        $user->changeEmail($email);
        $this->em->flush();

        $this->email->verification($user, ($this->issueToken)($user, TokenPurpose::VerifyEmail));

        $this->audit->record(SecurityEventType::AdminDataAccessed, $changedBy, [
            'action' => 'change_email',
            'subjectId' => $user->id()->toRfc4122(),
            'from' => $previous,
            'to' => $email,
        ]);

        return $user;
    }
}

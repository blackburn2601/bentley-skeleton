<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Acl\Application\AclFacade;
use DateTimeInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Assembles the administrative profile of one user account.
 *
 * Includes the effective permission list, which is the question an administrator actually
 * arrives with: not "what roles does this person have?" but "what can they do?". Those differ
 * whenever a group carries a role, which is the normal case.
 *
 * It comes from AclFacade::classLevelPermissionsOf() — the same call /me uses — rather than
 * being assembled here from roles. A second implementation that drifts would show an
 * administrator a list the server disagrees with, and nothing would fail (INV-19).
 */
final readonly class DescribeUserService
{
    public function __construct(
        private UserRepository $users,
        private AclFacade $acl,
    ) {
    }

    /**
     * @return array{
     *     id: string, email: string, status: string, emailVerified: bool, mfaEnabled: bool,
     *     failedLoginCount: int, lockedUntil: string|null, passwordChangedAt: string,
     *     createdAt: string, aclVersion: int,
     *     roles: list<string>, groups: list<string>, effectivePermissions: list<string>
     * }
     */
    public function __invoke(Uuid $userId): array
    {
        $user = $this->users->findById($userId);

        if (!$user instanceof User) {
            throw AccountException::noSuchAccount();
        }

        return [
            'id' => $user->id()->toRfc4122(),
            'email' => $user->email(),
            'status' => $user->status()->value,
            'emailVerified' => $user->isEmailVerified(),
            'mfaEnabled' => $user->hasMfaEnabled(),
            'failedLoginCount' => $user->failedLoginCount(),
            'lockedUntil' => $user->lockedUntil()?->format(DateTimeInterface::ATOM),
            'passwordChangedAt' => $user->passwordChangedAt()->format(DateTimeInterface::ATOM),
            'createdAt' => $user->createdAt()->format(DateTimeInterface::ATOM),
            // Exposed on purpose: it is what makes "a grant takes effect on the next request"
            // observable rather than a claim (ADR-0011).
            'aclVersion' => $user->aclVersion(),
            'roles' => $this->acl->directRoleNamesOf($userId),
            'groups' => $this->acl->groupNamesOf($userId),
            'effectivePermissions' => $this->acl->classLevelPermissionsOf($userId),
        ];
    }
}

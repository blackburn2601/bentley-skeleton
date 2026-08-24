<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\AccountException;
use App\Account\Domain\UserRepository;
use App\Acl\Application\AclFacade;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Assembles the profile the signed-in user's own client needs.
 */
final readonly class DescribeCurrentUserService
{
    public function __construct(
        private UserRepository $users,
        private AclFacade $acl,
    ) {
    }

    /**
     * @return array{id: string, username: string, roles: list<string>, permissions: list<string>}
     */
    public function __invoke(Uuid $userId): array
    {
        $user = $this->users->findById($userId);

        if (null === $user) {
            // The token was signed by us and its subject no longer exists — a deleted account
            // with a still-valid access token. Treated as a bad token rather than a 404,
            // because from the caller's point of view that is exactly what it is.
            throw AccountException::invalidToken();
        }

        return [
            'id' => $user->id()->toRfc4122(),
            'username' => $user->username(),
            'roles' => $this->acl->roleNamesOf($user->id()),
            'permissions' => $this->acl->classLevelPermissionsOf($userId),
        ];
    }
}

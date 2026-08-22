<?php

declare(strict_types=1);

namespace App\Account\Infrastructure;

use App\Account\Domain\User;
use App\Account\Domain\UserRepository;
use App\Shared\Application\AclVersionProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Account's side of the ACL cache contract.
 *
 * The counter is a column on User, so Account owns it; Acl only reads it as part of a cache
 * key. Implementing the Shared port here rather than letting Acl reach into User is what
 * keeps the two contexts independent (INV-02).
 */
final class UserAclVersionProvider implements AclVersionProvider
{
    /**
     * Read once per request per user.
     *
     * The version is on the critical path of every permission check, and a page rendering
     * fifty rows would otherwise re-read the same row fifty times.
     *
     * @var array<string, int>
     */
    private array $memo = [];

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function versionFor(Uuid $userId): int
    {
        $key = $userId->toRfc4122();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        // A missing user yields 0 rather than throwing: a permission check for a deleted
        // account should end in a plain denial, not a 500 during an incident.
        return $this->memo[$key] = $this->users->findById($userId)?->aclVersion() ?? 0;
    }

    public function bumpAll(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $user = $this->users->findById($userId);

            if (!$user instanceof User) {
                continue;
            }

            $user->bumpAclVersion();
            unset($this->memo[$userId->toRfc4122()]);
        }

        $this->em->flush();
    }
}

<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Application\AclCache;
use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\UserGroup;
use App\Shared\Application\AclVersionProvider;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Invalidates the cached authorization decisions belonging to a set of users.
 *
 * Every admin mutation ends here. It exists so that the fan-out — which users does this change
 * actually reach? — is written once instead of in each of the fourteen services that mutate a
 * grant, because the failure mode of getting it wrong is silent: the write succeeds, the
 * response is 200, and the user keeps their old access until the cache happens to expire.
 *
 * That is exactly the bug INV-13 and its end-to-end test exist to catch (ADR-0011: revocation
 * must take effect on the next request), so the fan-out is deliberately over-inclusive rather
 * than clever. Bumping a version too often costs one cache miss; bumping too rarely is a
 * security hole.
 */
final readonly class InvalidateAclCachesService
{
    public function __construct(
        private AclVersionProvider $versions,
        private SubjectRepository $subjects,
        private AclCache $cache,
    ) {
    }

    /**
     * @param list<Uuid> $userIds
     */
    public function forUsers(array $userIds): void
    {
        if ([] === $userIds) {
            return;
        }

        $this->versions->bumpAll($userIds);

        // The version bump invalidates Redis for the NEXT request. This drops the memo for
        // THIS one, which is the difference between an admin seeing the result of the grant
        // they just made and seeing the answer memoised moments earlier in the same request.
        $this->cache->forgetRequestScope();
    }

    /**
     * A group's members, because a group grant is held by everyone in it.
     *
     * Read the membership before the caller removes anyone: a user dropped from the group in
     * the same transaction still holds cached decisions granted through it.
     */
    public function forGroup(UserGroup $group): void
    {
        $this->forUsers($this->subjects->memberIdsOf($group));
    }

    /** Direct holders of the role plus members of every group carrying it. */
    public function forRole(Role $role): void
    {
        $this->forUsers($this->subjects->userIdsWithRole($role));
    }
}

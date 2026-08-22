<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * The current `acl_version` of a user.
 *
 * Lives in Shared because it is a contract *between* two contexts: Account owns the counter
 * (it is a column on User), and Acl consumes it as part of every cache key. Neither may
 * depend on the other's internals (INV-02), and Shared is the layer both may depend on.
 *
 * The contract is deliberately one integer. Acl does not need to know what a user is; it
 * needs to know when its cached answers stopped being true.
 */
interface AclVersionProvider
{
    public function versionFor(Uuid $userId): int;

    /**
     * Invalidate every cached decision for these users by bumping their version.
     *
     * Called after any role, group or ACE change. Takes a list because a single group grant
     * affects everyone in the group.
     *
     * @param list<Uuid> $userIds
     */
    public function bumpAll(array $userIds): void;
}

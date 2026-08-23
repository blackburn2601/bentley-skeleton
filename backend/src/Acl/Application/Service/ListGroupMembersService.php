<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Account\Application\AccountFacade;
use App\Acl\Domain\AclException;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Lists the users who belong to one group.
 *
 * Membership is stored as bare user ids, so the emails come from the Account context one at a
 * time through its facade. That is an N+1, accepted because a group is a team rather than a
 * mailing list — if groups ever grow past a few hundred people, this is the first thing to
 * replace with a batch lookup on the facade.
 */
final readonly class ListGroupMembersService
{
    public function __construct(
        private UserGroupRepository $groups,
        private SubjectRepository $subjects,
        private AccountFacade $accounts,
    ) {
    }

    /**
     * @return list<array{id: string, email: string}>
     */
    public function __invoke(Uuid $groupId): array
    {
        $group = $this->groups->findById($groupId);

        if (!$group instanceof UserGroup) {
            throw AclException::noSuchGroup();
        }

        $members = [];

        foreach ($this->subjects->memberIdsOf($group) as $memberId) {
            $members[] = [
                'id' => $memberId->toRfc4122(),
                // An id with no email is a membership row pointing at an account that has been
                // erased. Showing the id is more honest than hiding the row.
                'email' => $this->accounts->emailOf($memberId) ?? '(erased account)',
            ];
        }

        usort($members, static fn (array $a, array $b): int => $a['email'] <=> $b['email']);

        return $members;
    }
}

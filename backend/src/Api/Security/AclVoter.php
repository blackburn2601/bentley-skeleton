<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Acl\Application\AclFacade;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;

/**
 * Bridges Symfony's `#[IsGranted]` to the ACL.
 *
 * One voter for every permission rather than one per resource type. The attribute string IS
 * the permission name, so `#[IsGranted('note.update', subject: 'note')]` reads as the
 * permission it requires, and `docs/PERMISSIONS.md` lists exactly the strings that appear in
 * controllers.
 *
 * @extends Voter<string, object|null>
 */
final class AclVoter extends Voter
{
    public function __construct(private readonly AclFacade $acl)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Only permission-shaped attributes. Symfony's own attributes (IS_AUTHENTICATED,
        // PUBLIC_ACCESS, ROLE_*) must fall through to their own voters — claiming them here
        // would silently take over authentication decisions this class knows nothing about.
        return 1 === preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $userId = $this->userIdFrom($token);

        if (!$userId instanceof Uuid) {
            return false;
        }

        // A non-object subject (an id, a string) is refused rather than silently treated as a
        // class-level check: `#[IsGranted('note.read', subject: 'id')]` looks like an
        // object-level check and would quietly become a much weaker one.
        if (null !== $subject && !\is_object($subject)) {
            return false;
        }

        return $this->acl->isGranted($userId, $attribute, $subject);
    }

    private function userIdFrom(TokenInterface $token): ?Uuid
    {
        $user = $token->getUser();

        if (!$user instanceof AuthenticatedUser) {
            return null;
        }

        return $user->id();
    }
}

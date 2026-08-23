<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use App\Shared\Domain\DomainProblem;
use App\Shared\Domain\ProblemKind;
use RuntimeException;

/**
 * Something the Acl context refuses to do.
 *
 * Domain exceptions, not HTTP ones (INV-08, INV-17). The problem+json listener is the single
 * place that turns these into status codes, so a service stays callable from a console command
 * or a test without pretending to be a web request.
 */
final class AclException extends RuntimeException implements DomainProblem
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        private readonly ProblemKind $kind = ProblemKind::Invalid,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function kind(): ProblemKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function roleNameTaken(string $name): self
    {
        return new self(\sprintf('A role named "%s" already exists.', $name), ProblemKind::Conflict);
    }

    /**
     * ROLE_SUPER_ADMIN and ROLE_USER are recreated by EnsureBaselineRolesService, so deleting
     * one is not permanent — but every holder loses their access in the meantime, and the
     * application cannot function without them. Refuse rather than repair.
     */
    public static function roleIsBaseline(string $name): self
    {
        return new self(
            \sprintf('"%s" is a baseline role. The application cannot run without it.', $name),
            ProblemKind::Conflict,
        );
    }

    /**
     * Attaching permissions to the super-admin role would imply a meaning it does not have: it
     * short-circuits the resolver, so its permission list is never consulted. A list on it
     * reads as the definitive answer to "what can an admin do?" and would be a lie.
     */
    public static function superAdminHasNoPermissionList(): self
    {
        return new self(
            'ROLE_SUPER_ADMIN short-circuits every check, so it carries no permission list.',
            ProblemKind::Conflict,
        );
    }

    public static function noSuchPermission(string $name): self
    {
        return new self(\sprintf('No permission named "%s" exists.', $name), ProblemKind::Invalid);
    }

    /**
     * The escalation ceiling.
     *
     * Without it, `permission.grant` is silently equivalent to ROLE_SUPER_ADMIN: anyone who
     * may edit a role can attach `user.delete` to a role they hold, and award themselves
     * anything. Super admins are exempt because they already hold everything.
     */
    public static function cannotGrantWhatYouDoNotHold(string $permission): self
    {
        return new self(
            \sprintf('You cannot grant "%s", because you do not hold it yourself.', $permission),
            ProblemKind::Forbidden,
        );
    }

    public static function groupNameTaken(string $name): self
    {
        return new self(\sprintf('A group named "%s" already exists.', $name), ProblemKind::Conflict);
    }

    public static function noSuchMember(string $id): self
    {
        return new self(\sprintf('No account exists with id %s.', $id), ProblemKind::Invalid);
    }

    public static function noSuchGroup(): self
    {
        return new self('No such group.', ProblemKind::NotFound);
    }

    public static function noSuchRole(string $name): self
    {
        return new self(\sprintf('No role named "%s" exists.', $name), ProblemKind::NotFound);
    }
}

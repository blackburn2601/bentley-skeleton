<?php

declare(strict_types=1);

namespace App\Acl\Domain;

/**
 * Which level of specificity decided a permission check.
 *
 * Reported by PermissionResolver::explain(). Knowing that a grant came from a *class-level*
 * entry rather than the object is usually the whole answer to "why can they see this?".
 */
enum AclTier: string
{
    case Object = 'object';
    case Inherited = 'inherited';
    case ClassLevel = 'class';
    case Rbac = 'rbac';
    case SuperAdmin = 'super_admin';
    case Default = 'default';

    public function describe(): string
    {
        return match ($this) {
            self::Object => 'an entry on this specific object',
            self::Inherited => 'an entry on an object this one inherits from',
            self::ClassLevel => 'a class-level entry covering every object of this type',
            self::Rbac => 'a role that carries this permission',
            self::SuperAdmin => 'the super-admin short-circuit',
            self::Default => 'nothing granted it — access is denied by default',
        };
    }
}

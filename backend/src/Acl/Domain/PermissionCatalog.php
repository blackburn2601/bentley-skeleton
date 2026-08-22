<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use ReflectionClass;

/**
 * Every permission this application knows about, declared in code.
 *
 * Permissions are constants rather than rows because a permission is part of the program:
 * endpoints reference it, and it must exist identically in every environment. Declaring it
 * here makes adding one a reviewable diff, keeps it in step with the code that uses it, and
 * means a redeploy cannot leave an environment missing a permission that an endpoint
 * requires. `bin/console app:acl:sync-permissions` reconciles this list into the database.
 *
 * *Grants* are the opposite — they are data, and live in `acl_entry`.
 *
 * Naming: `<resource>.<verb>`, lowercase, singular resource. docs/PERMISSIONS.md groups on
 * the prefix, so consistency here is what keeps that document readable.
 *
 * See docs/cookbook/add-permission.md.
 */
final class PermissionCatalog
{
    // --- Own account -------------------------------------------------------------
    public const string ACCOUNT_READ = 'account.read';
    public const string ACCOUNT_UPDATE = 'account.update';
    public const string ACCOUNT_DELETE = 'account.delete';
    public const string ACCOUNT_EXPORT = 'account.export';

    // --- User administration -----------------------------------------------------
    public const string USER_READ = 'user.read';
    public const string USER_CREATE = 'user.create';
    public const string USER_UPDATE = 'user.update';
    public const string USER_DELETE = 'user.delete';
    public const string USER_IMPERSONATE = 'user.impersonate';

    // --- Groups ------------------------------------------------------------------
    public const string GROUP_READ = 'group.read';
    public const string GROUP_CREATE = 'group.create';
    public const string GROUP_UPDATE = 'group.update';
    public const string GROUP_DELETE = 'group.delete';

    // --- Roles -------------------------------------------------------------------
    public const string ROLE_READ = 'role.read';
    public const string ROLE_CREATE = 'role.create';
    public const string ROLE_UPDATE = 'role.update';
    public const string ROLE_DELETE = 'role.delete';

    // --- Permissions and grants --------------------------------------------------
    public const string PERMISSION_READ = 'permission.read';
    public const string PERMISSION_GRANT = 'permission.grant';
    public const string PERMISSION_REVOKE = 'permission.revoke';

    /** Answers "why can/can't this user do this?" — see PermissionResolver::explain(). */
    public const string PERMISSION_EXPLAIN = 'permission.explain';

    // --- Audit -------------------------------------------------------------------
    public const string AUDIT_READ = 'audit.read';
    public const string AUDIT_EXPORT = 'audit.export';

    private function __construct()
    {
    }

    /**
     * Every declared permission, for `app:acl:sync-permissions` and the docs generator.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        /** @var list<string> $values */
        $values = array_values(new ReflectionClass(self::class)->getConstants());

        sort($values);

        return $values;
    }
}

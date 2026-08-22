<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Declares the foreign keys that cross a context boundary.
 *
 * `user_role.user_id` and `group_membership.user_id` point at `"user"`, but they are plain
 * UUID columns rather than Doctrine associations: the Acl context may not depend on
 * Account's entities (INV-02), and giving two contexts write access to the same
 * authorization rows is how one of them forgets to bump `acl_version`.
 *
 * Referential integrity is still worth having. Without these constraints, deleting a user
 * leaves orphaned role assignments that would be silently re-attached to whoever next
 * received that UUID.
 *
 * Adding them here rather than only in the migration keeps three things true at once:
 *
 *   - `doctrine:schema:validate` reports in sync, so the real check stays useful instead of
 *     being run with --skip-sync and quietly ignoring everything;
 *   - `doctrine:migrations:diff` does not try to DROP them on the next schema change;
 *   - the constraint is declared next to the reason it exists.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class CrossContextForeignKeyListener
{
    /**
     * Referenced by table name, not by entity: naming Account's entity here would be the
     * dependency this whole arrangement avoids.
     */
    private const array FOREIGN_KEYS = [
        ['user_role', 'fk_user_role_user'],
        ['group_membership', 'fk_group_membership_user'],
    ];

    private const string USER_TABLE = 'user';

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();

        if (!$schema->hasTable(self::USER_TABLE)) {
            return;
        }

        foreach (self::FOREIGN_KEYS as [$tableName, $constraintName]) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }

            $table = $schema->getTable($tableName);

            if ($table->hasForeignKey($constraintName)) {
                continue;
            }

            $table->addForeignKeyConstraint(
                self::USER_TABLE,
                ['user_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                $constraintName,
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The append-only security event log (ADR-0012).
 *
 * Reviewed after generation per docs/cookbook/add-migration.md. The important part is not the
 * table — it is the grant below it.
 */
final class Version20260822184438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Append-only security_event log, with INSERT-only grants for the application role';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE security_event (id UUID NOT NULL, type VARCHAR(64) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, actor_id UUID DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, request_id VARCHAR(64) DEFAULT NULL, payload JSONB NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_security_event_actor ON security_event (actor_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_security_event_type ON security_event (type, occurred_at)');
        $this->addSql('CREATE INDEX idx_security_event_occurred ON security_event (occurred_at)');

        // This is what makes the log evidence rather than a table we promise not to edit.
        //
        // The application's database role gets INSERT and SELECT and nothing else, so code
        // execution in the application still cannot rewrite history. Retention runs as the
        // owner, separately (docs/OPERATIONS.md).
        //
        // Guarded on the role existing: development runs as a single owner role and has
        // nothing to restrict, while production provisions bentley_app separately. Making the
        // grant conditional means one migration is correct in both, instead of a migration
        // that only production can apply.
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = current_setting('bentley.app_role', true)) THEN
                    EXECUTE format('REVOKE ALL ON TABLE security_event FROM %I', current_setting('bentley.app_role', true));
                    EXECUTE format('GRANT INSERT, SELECT ON TABLE security_event TO %I', current_setting('bentley.app_role', true));
                END IF;
            END
            $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        // Dropping the table takes the grants with it.
        $this->addSql('DROP TABLE security_event');
    }
}

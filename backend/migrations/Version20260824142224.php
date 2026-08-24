<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Workforce identity: replace email with username, drop email/MFA/token infrastructure.
 *
 * See ADR-0024. `username` becomes the case-insensitive identity (citext), backfilled from the
 * local part of each existing email. The email columns, the TOTP secret column and the
 * single-use-token table are dropped: there is no email verification, no email-based password
 * reset and no MFA in the workforce model.
 *
 * This migration is DESTRUCTIVE on `email` — the column and its data are dropped once `username`
 * is populated. A deploy that runs this cannot roll back to the email model without losing the
 * original addresses (down() fabricates `@restored.invalid` placeholders, not the real data).
 */
final class Version20260824142224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace email with username (citext); drop email verification, TOTP and single-use tokens (ADR-0024).';
    }

    public function up(Schema $schema): void
    {
        // The single-use-token table backs email verification and email-based password reset,
        // both removed in the workforce model (ADR-0024). Drop the FK before the table.
        $this->addSql('ALTER TABLE single_use_token DROP CONSTRAINT fk_7bf34104a76ed395');
        $this->addSql('DROP TABLE single_use_token');

        // The email unique index references a column we are about to drop; it must go first.
        $this->addSql('DROP INDEX uniq_8d93d649e7927c74');

        // Add the new identity column nullable so the backfill can populate it before the NOT
        // NULL constraint is applied — the three-step NOT NULL pattern (add, fill, constrain).
        $this->addSql('ALTER TABLE "user" ADD username CITEXT DEFAULT NULL');

        // Backfill from the local part of each email. The boilerplate's demo accounts
        // (admin/editor/viewer) have distinct local parts, so this yields unique usernames.
        // Real data with shared local parts across domains would need a collision strategy;
        // this is a boilerplate migration, and the destructive caveat is recorded in ADR-0024.
        $this->addSql('UPDATE "user" SET username = split_part(email, \'@\', 1)');

        $this->addSql('ALTER TABLE "user" ALTER COLUMN username SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_8d93d649f85e0677 ON "user" (username)');

        // The email data is now reflected in `username`; the original addresses are dropped.
        $this->addSql('ALTER TABLE "user" DROP COLUMN email');
        $this->addSql('ALTER TABLE "user" DROP COLUMN email_verified_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN totp_secret_encrypted');
    }

    public function down(Schema $schema): void
    {
        // Reverse the rename: re-add the email columns, fabricate addresses from the username
        // (the original addresses are gone — see the class docblock), then drop `username`.
        $this->addSql('ALTER TABLE "user" ADD email CITEXT DEFAULT NULL');
        $this->addSql('UPDATE "user" SET email = username || \'@restored.invalid\'');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN email SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_8d93d649e7927c74 ON "user" (email)');

        $this->addSql('ALTER TABLE "user" ADD email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_secret_encrypted TEXT DEFAULT NULL');

        $this->addSql('DROP INDEX uniq_8d93d649f85e0677');
        $this->addSql('ALTER TABLE "user" DROP COLUMN username');

        // Recreate the single-use-token table, mirroring its original definition.
        $this->addSql('CREATE TABLE single_use_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_7bf34104a76ed395 ON single_use_token (user_id)');
        $this->addSql('CREATE INDEX idx_single_use_token_user_purpose ON single_use_token (user_id, purpose)');
        $this->addSql('CREATE UNIQUE INDEX uniq_7bf34104b3bc57da ON single_use_token (token_hash)');
        $this->addSql('ALTER TABLE single_use_token ADD CONSTRAINT fk_7bf34104a76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}

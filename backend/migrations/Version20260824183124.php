<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional TOTP MFA (ADR-0026), superseding the no-MFA half of ADR-0024.
 *
 * Re-adds `totp_secret_encrypted` (dropped by Version20260824142224) and adds the columns and
 * table the optional second factor needs: a provisional secret slot, an admin `mfa_required`
 * flag, the `amr` claim on `refresh_token` so a refresh reissues an MFA-verified access token,
 * and the `mfa_recovery_code` table for single-use recovery codes.
 *
 * No grants: `mfa_recovery_code` is an ordinary application table the app reads, writes and
 * deletes, matching `refresh_token` (whose initial migration carries no grants either). Only
 * the append-only `security_event` is granted INSERT-only — see Version20260822184438.
 */
final class Version20260824183124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-add TOTP + add optional MFA columns and the mfa_recovery_code table (ADR-0026).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mfa_recovery_code (id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6BC743A4E7530879 ON mfa_recovery_code (code_hash)');
        $this->addSql('CREATE INDEX idx_mfa_recovery_code_user ON mfa_recovery_code (user_id)');
        $this->addSql('ALTER TABLE mfa_recovery_code ADD CONSTRAINT FK_6BC743A4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE "user" ADD totp_secret_encrypted TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_secret_encrypted_provisional TEXT DEFAULT NULL');
        // DEFAULT FALSE so existing rows backfill to "no MFA required" in the same statement;
        // the three-step nullable pattern is unnecessary when the default fills the rows.
        $this->addSql('ALTER TABLE "user" ADD mfa_required BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN mfa_required DROP DEFAULT');

        // DEFAULT '[]' so existing refresh rows backfill to "no second factor"; the column is
        // NOT NULL, so the default is what populates it for rows already in the table.
        $this->addSql('ALTER TABLE refresh_token ADD amr JSON NOT NULL DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE refresh_token ALTER COLUMN amr DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mfa_recovery_code DROP CONSTRAINT FK_6BC743A4A76ED395');
        $this->addSql('DROP TABLE mfa_recovery_code');
        $this->addSql('ALTER TABLE refresh_token DROP amr');
        $this->addSql('ALTER TABLE "user" DROP totp_secret_encrypted');
        $this->addSql('ALTER TABLE "user" DROP totp_secret_encrypted_provisional');
        $this->addSql('ALTER TABLE "user" DROP mfa_required');
    }
}

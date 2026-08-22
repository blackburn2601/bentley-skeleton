<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema: identity, the per-object ACL, and the tokens that support both.
 *
 * Reviewed after generation, per docs/cookbook/add-migration.md. Three things the diff could
 * not know, each marked below:
 *
 *  1. citext must exist before "user".email can use it.
 *  2. user_role.user_id and group_membership.user_id are deliberately NOT Doctrine
 *     associations — Acl may not depend on Account's entities — but they are still foreign
 *     keys, and the database is the right place to say so.
 *  3. down() must actually reverse up(). CI runs both on a scratch database.
 */
final class Version20260822175827 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, tokens, roles, groups, permissions and acl_entry';
    }

    public function up(Schema $schema): void
    {
        // (1) Required by "user".email. The dev container also creates it from
        // docker/postgres/init/, but CI and production build the schema from migrations
        // alone, so it has to be here too. IF NOT EXISTS keeps both paths idempotent.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citext');

        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email CITEXT NOT NULL, password_hash VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL, email_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, totp_secret_encrypted TEXT DEFAULT NULL, failed_login_count INT NOT NULL, locked_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, password_changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, acl_version INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX idx_user_status ON "user" (status)');
        $this->addSql('CREATE TABLE acl_entry (id UUID NOT NULL, subject_type VARCHAR(16) NOT NULL, subject_id UUID NOT NULL, resource_class VARCHAR(255) NOT NULL, resource_id UUID DEFAULT NULL, effect VARCHAR(8) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, granted_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, permission_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AFB8D0CFFED90CCA ON acl_entry (permission_id)');
        $this->addSql('CREATE INDEX idx_acl_entry_resource ON acl_entry (resource_class, resource_id, permission_id)');
        $this->addSql('CREATE INDEX idx_acl_entry_subject ON acl_entry (subject_type, subject_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_acl_entry ON acl_entry (subject_type, subject_id, resource_class, resource_id, permission_id)');
        $this->addSql('CREATE TABLE group_membership (id UUID NOT NULL, user_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, group_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_5132B337FE54D947 ON group_membership (group_id)');
        $this->addSql('CREATE INDEX idx_group_membership_user ON group_membership (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_membership ON group_membership (user_id, group_id)');
        $this->addSql('CREATE TABLE permission (id UUID NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E04992AA5E237E06 ON permission (name)');
        $this->addSql('CREATE TABLE refresh_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, family_id UUID NOT NULL, parent_id UUID DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, replaced_by UUID DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C74F2195B3BC57DA ON refresh_token (token_hash)');
        $this->addSql('CREATE INDEX idx_refresh_token_family ON refresh_token (family_id)');
        $this->addSql('CREATE INDEX idx_refresh_token_user ON refresh_token (user_id)');
        $this->addSql('CREATE TABLE role (id UUID NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_57698A6A5E237E06 ON role (name)');
        $this->addSql('CREATE TABLE role_permission (role_id UUID NOT NULL, permission_id UUID NOT NULL, PRIMARY KEY (role_id, permission_id))');
        $this->addSql('CREATE INDEX IDX_6F7DF886D60322AC ON role_permission (role_id)');
        $this->addSql('CREATE INDEX IDX_6F7DF886FED90CCA ON role_permission (permission_id)');
        $this->addSql('CREATE TABLE single_use_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, purpose VARCHAR(255) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7BF34104B3BC57DA ON single_use_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_7BF34104A76ED395 ON single_use_token (user_id)');
        $this->addSql('CREATE INDEX idx_single_use_token_user_purpose ON single_use_token (user_id, purpose)');
        $this->addSql('CREATE TABLE user_group (id UUID NOT NULL, name VARCHAR(100) NOT NULL, description VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F02BF9D5E237E06 ON user_group (name)');
        $this->addSql('CREATE TABLE group_role (user_group_id UUID NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (user_group_id, role_id))');
        $this->addSql('CREATE INDEX IDX_7E33D11A1ED93D47 ON group_role (user_group_id)');
        $this->addSql('CREATE INDEX IDX_7E33D11AD60322AC ON group_role (role_id)');
        $this->addSql('CREATE TABLE user_role (id UUID NOT NULL, user_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, role_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2DE8C6A3D60322AC ON user_role (role_id)');
        $this->addSql('CREATE INDEX idx_user_role_user ON user_role (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_role ON user_role (user_id, role_id)');
        $this->addSql('ALTER TABLE acl_entry ADD CONSTRAINT FK_AFB8D0CFFED90CCA FOREIGN KEY (permission_id) REFERENCES permission (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_membership ADD CONSTRAINT FK_5132B337FE54D947 FOREIGN KEY (group_id) REFERENCES user_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F2195A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_6F7DF886D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permission ADD CONSTRAINT FK_6F7DF886FED90CCA FOREIGN KEY (permission_id) REFERENCES permission (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE single_use_token ADD CONSTRAINT FK_7BF34104A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_role ADD CONSTRAINT FK_7E33D11A1ED93D47 FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE group_role ADD CONSTRAINT FK_7E33D11AD60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT FK_2DE8C6A3D60322AC FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE NOT DEFERRABLE');

        // (2) The Acl context stores subject ids rather than entity references, so Doctrine
        // does not generate these. Referential integrity is still worth having: without them,
        // deleting a user silently leaves orphaned role assignments and group memberships
        // that would be re-attached to whoever next received that UUID.
        $this->addSql('ALTER TABLE user_role ADD CONSTRAINT fk_user_role_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_membership ADD CONSTRAINT fk_group_membership_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // (3) Reverses up() exactly. The hand-added constraints go first, then Doctrine's.
        $this->addSql('ALTER TABLE user_role DROP CONSTRAINT fk_user_role_user');
        $this->addSql('ALTER TABLE group_membership DROP CONSTRAINT fk_group_membership_user');
        $this->addSql('ALTER TABLE acl_entry DROP CONSTRAINT FK_AFB8D0CFFED90CCA');
        $this->addSql('ALTER TABLE group_membership DROP CONSTRAINT FK_5132B337FE54D947');
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_C74F2195A76ED395');
        $this->addSql('ALTER TABLE role_permission DROP CONSTRAINT FK_6F7DF886D60322AC');
        $this->addSql('ALTER TABLE role_permission DROP CONSTRAINT FK_6F7DF886FED90CCA');
        $this->addSql('ALTER TABLE single_use_token DROP CONSTRAINT FK_7BF34104A76ED395');
        $this->addSql('ALTER TABLE group_role DROP CONSTRAINT FK_7E33D11A1ED93D47');
        $this->addSql('ALTER TABLE group_role DROP CONSTRAINT FK_7E33D11AD60322AC');
        $this->addSql('ALTER TABLE user_role DROP CONSTRAINT FK_2DE8C6A3D60322AC');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE acl_entry');
        $this->addSql('DROP TABLE group_membership');
        $this->addSql('DROP TABLE permission');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE role');
        $this->addSql('DROP TABLE role_permission');
        $this->addSql('DROP TABLE single_use_token');
        $this->addSql('DROP TABLE user_group');
        $this->addSql('DROP TABLE group_role');
        $this->addSql('DROP TABLE user_role');

        // citext is deliberately NOT dropped. Another schema in the same database may be
        // using it, and DROP EXTENSION would take their columns with it.
    }
}

<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One access-control entry: this subject, this permission, on this resource, allowed or denied.
 *
 * The whole per-object model is this table (ADR-0003).
 *
 * `resourceId === null` means a **class-level** grant: "may read every Note", as opposed to
 * "may read this Note". Encoding both in one table is what lets the resolver walk from most
 * specific to least in a single, uniform structure — and what lets `AclCriteriaBuilder`
 * express the same rules as one SQL predicate.
 *
 * `expiresAt` supports time-boxed access — a contractor, an escalation, an audit window —
 * without anyone having to remember to revoke it. Expired entries are ignored by the
 * resolver, not deleted, so the history of who had access when survives.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acl_entry')]
#[ORM\UniqueConstraint(
    name: 'uniq_acl_entry',
    columns: ['subject_type', 'subject_id', 'resource_class', 'resource_id', 'permission_id'],
)]
// The resolver's hot path: "every entry for this resource and permission".
#[ORM\Index(name: 'idx_acl_entry_resource', columns: ['resource_class', 'resource_id', 'permission_id'])]
// The subject-set lookup, and what AclCriteriaBuilder joins on.
#[ORM\Index(name: 'idx_acl_entry_subject', columns: ['subject_type', 'subject_id'])]
class AclEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(type: 'string', length: 16, enumType: AclSubjectType::class)]
        private AclSubjectType $subjectType,
        /** No foreign key: this may be a user, a group or a role id. */
        #[ORM\Column(type: 'uuid')]
        private Uuid $subjectId,
        #[ORM\Column(type: 'string', length: 255)]
        private string $resourceClass,
        /** NULL means class-level: every instance of $resourceClass. */
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $resourceId,
        #[ORM\ManyToOne(targetEntity: Permission::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Permission $permission,
        #[ORM\Column(type: 'string', length: 8, enumType: AclEffect::class)]
        private AclEffect $effect,
        #[ORM\Column(type: 'datetime_immutable')]
        private DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $expiresAt = null,
        /** Who granted this. Answering "who gave them access?" is half of any access review. */
        #[ORM\Column(type: 'uuid', nullable: true)]
        private ?Uuid $grantedBy = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function subjectType(): AclSubjectType
    {
        return $this->subjectType;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function resourceClass(): string
    {
        return $this->resourceClass;
    }

    public function resourceId(): ?Uuid
    {
        return $this->resourceId;
    }

    public function permission(): Permission
    {
        return $this->permission;
    }

    public function effect(): AclEffect
    {
        return $this->effect;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function grantedBy(): ?Uuid
    {
        return $this->grantedBy;
    }

    public function isClassLevel(): bool
    {
        return !$this->resourceId instanceof Uuid;
    }

    public function isEffectiveAt(DateTimeImmutable $now): bool
    {
        return !$this->expiresAt instanceof DateTimeImmutable || $this->expiresAt > $now;
    }
}

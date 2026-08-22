<?php

declare(strict_types=1);

namespace App\Acl\Domain;

/**
 * What kind of thing a grant is made to.
 *
 * Untyped by design: `acl_entry.subject_id` is a bare UUID with no foreign key, because a
 * grant may target a user, a group or a role. This is also the seam through which tenancy
 * would arrive — one more dimension of the subject set, not a rewrite (ADR-0014).
 */
enum AclSubjectType: string
{
    case User = 'user';
    case Group = 'group';
    case Role = 'role';
}

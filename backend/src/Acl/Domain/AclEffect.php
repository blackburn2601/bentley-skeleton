<?php

declare(strict_types=1);

namespace App\Acl\Domain;

/**
 * Whether an entry grants or refuses.
 *
 * Explicit deny exists so that an exception can be carved out of a broad grant — "everyone in
 * Support may read tickets, except this contractor, on this ticket" — without having to
 * replace the broad grant with hundreds of narrow ones.
 *
 * Within a tier, deny always wins (see PermissionResolver). Anything else would make a deny
 * depend on evaluation order, and a security rule whose outcome depends on row order is not
 * a rule.
 */
enum AclEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}

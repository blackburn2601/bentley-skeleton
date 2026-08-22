<?php

declare(strict_types=1);

namespace App\Api\Attribute;

use Attribute;

/**
 * Declares that an endpoint genuinely has nothing to delegate.
 *
 * INV-07 requires every controller to call an Application service, because a controller that
 * does its own work hides business logic behind an HTTP boundary. A very small number of
 * endpoints have no work at all — a liveness probe answers "this process can execute PHP",
 * and inventing a service to say so would be exactly the ceremony INV-12 rejects.
 *
 * This attribute exists so that such a case is *declared* rather than silently tolerated,
 * for the same reason `#[IsGranted('PUBLIC_ACCESS')]` exists: the alternative is an implicit
 * exemption that looks identical to an oversight.
 *
 * The reason is mandatory, and `grep -r NoServiceDelegation src/` lists every exemption in
 * the codebase. If that list is longer than a couple of entries, the rule is not the problem.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class NoServiceDelegation
{
    public function __construct(public string $reason)
    {
    }
}

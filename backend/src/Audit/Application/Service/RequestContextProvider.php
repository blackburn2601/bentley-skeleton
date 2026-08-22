<?php

declare(strict_types=1);

namespace App\Audit\Application\Service;

use App\Audit\Application\RequestContext;

/**
 * The current request's origin, if there is one.
 *
 * A port so the Application layer can record where an action came from without importing
 * anything from HttpFoundation (INV-08). The HTTP adapter reads the real request; the
 * console gets an empty context, and the audit row honestly says "no IP" rather than
 * inventing one.
 */
interface RequestContextProvider
{
    public function current(): RequestContext;
}

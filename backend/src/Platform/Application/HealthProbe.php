<?php

declare(strict_types=1);

namespace App\Platform\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One dependency this application needs in order to serve traffic.
 *
 * A port with several implementations (database, cache, schema state), which is the bar
 * INV-12 sets for introducing an interface. Adding a dependency to the readiness check means
 * adding an implementation — no existing code changes.
 */
#[AutoconfigureTag]
interface HealthProbe
{
    /** Stable key used in the readiness response body, e.g. "database". */
    public function name(): string;

    /**
     * Must not throw. A probe that throws is a probe that takes the health endpoint down
     * with it, which is the opposite of useful during an incident.
     */
    public function check(): HealthProbeResult;
}

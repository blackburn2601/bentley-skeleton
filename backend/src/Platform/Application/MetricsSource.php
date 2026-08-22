<?php

declare(strict_types=1);

namespace App\Platform\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Something that has numbers worth scraping.
 *
 * A port with several implementations, so adding a metric means adding a class rather than
 * editing a growing collector — the same shape as HealthProbe, for the same reason.
 */
#[AutoconfigureTag]
interface MetricsSource
{
    /** @return list<Metric> */
    public function metrics(): array;
}

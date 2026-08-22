<?php

declare(strict_types=1);

namespace App\Platform\Application;

/**
 * The outcome of a single readiness probe.
 */
final readonly class HealthProbeResult
{
    private function __construct(
        public bool $healthy,
        public ?string $detail = null,
    ) {
    }

    public static function up(): self
    {
        return new self(true);
    }

    /**
     * @param string $detail why it is down — shown in the response body, so it must not
     *                       contain credentials or connection strings
     */
    public static function down(string $detail): self
    {
        return new self(false, $detail);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\Application;

/**
 * One Prometheus metric family.
 */
final readonly class Metric
{
    /**
     * @param 'counter'|'gauge'|'histogram' $type
     * @param array<string, float>          $samples label string ("a=\"1\",b=\"2\"") => value;
     *                                               the empty string means no labels
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $help,
        public array $samples,
    ) {
    }

    public static function gauge(string $name, string $help, float $value): self
    {
        return new self($name, 'gauge', $help, ['' => $value]);
    }

    /**
     * @param array<string, float> $samples
     */
    public static function gaugeWithLabels(string $name, string $help, array $samples): self
    {
        return new self($name, 'gauge', $help, $samples);
    }
}

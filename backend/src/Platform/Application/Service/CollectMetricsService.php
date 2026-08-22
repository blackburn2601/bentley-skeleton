<?php

declare(strict_types=1);

namespace App\Platform\Application\Service;

use App\Platform\Application\MetricsSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * @responsibility Gathers the application's metrics into Prometheus exposition format.
 */
final readonly class CollectMetricsService
{
    /**
     * @param iterable<MetricsSource> $sources
     */
    public function __construct(
        #[AutowireIterator(MetricsSource::class)]
        private iterable $sources,
    ) {
    }

    /**
     * Prometheus text exposition format, built by hand.
     *
     * A client library would bring a storage backend and a shared-memory adapter for a handful
     * of gauges. The format is a documented, stable, line-based text protocol — writing it
     * directly costs less than operating a dependency that does.
     */
    public function __invoke(): string
    {
        $lines = [];

        foreach ($this->sources as $source) {
            foreach ($source->metrics() as $metric) {
                $lines[] = \sprintf('# HELP %s %s', $metric->name, $metric->help);
                $lines[] = \sprintf('# TYPE %s %s', $metric->name, $metric->type);

                foreach ($metric->samples as $labels => $value) {
                    $lines[] = '' === $labels
                        ? \sprintf('%s %s', $metric->name, $this->format($value))
                        : \sprintf('%s{%s} %s', $metric->name, $labels, $this->format($value));
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function format(float $value): string
    {
        // Prometheus wants a plain decimal; PHP would otherwise render large values in
        // scientific notation, which the scraper rejects.
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        // Trimming leaves an empty string for exactly zero.
        return '' === $formatted ? '0' : $formatted;
    }
}

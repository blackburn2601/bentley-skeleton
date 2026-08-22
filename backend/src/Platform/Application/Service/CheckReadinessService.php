<?php

declare(strict_types=1);

namespace App\Platform\Application\Service;

use App\Platform\Application\HealthProbe;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * @responsibility Reports whether every dependency this application needs is reachable.
 */
final readonly class CheckReadinessService
{
    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        #[AutowireIterator(HealthProbe::class)]
        private iterable $probes,
    ) {
    }

    /**
     * Runs every probe, even after one fails.
     *
     * Short-circuiting would be cheaper and much less useful: during an incident you want to
     * know whether it is only Redis or everything, and a first-failure-wins response makes
     * you redeploy to find out.
     *
     * @return array{ready: bool, checks: array<string, array{status: string, detail?: string}>}
     */
    public function __invoke(): array
    {
        $ready = true;
        $checks = [];

        foreach ($this->probes as $probe) {
            $result = $probe->check();
            $ready = $ready && $result->healthy;

            $checks[$probe->name()] = null === $result->detail
                ? ['status' => $result->healthy ? 'up' : 'down']
                : ['status' => $result->healthy ? 'up' : 'down', 'detail' => $result->detail];
        }

        return ['ready' => $ready, 'checks' => $checks];
    }
}

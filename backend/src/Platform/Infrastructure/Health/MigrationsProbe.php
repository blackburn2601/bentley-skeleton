<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Health;

use App\Platform\Application\HealthProbe;
use App\Platform\Application\HealthProbeResult;
use Doctrine\Migrations\DependencyFactory;
use Throwable;

/**
 * Is the schema at the version this code expects?
 *
 * This is the probe that matters during a rollout. A container whose code is newer than the
 * schema will fail on the first query that touches a new column — but only for the requests
 * unlucky enough to hit it. Reporting not-ready keeps it out of the load balancer until the
 * migration job has finished.
 */
final readonly class MigrationsProbe implements HealthProbe
{
    public function __construct(private DependencyFactory $migrations)
    {
    }

    public function name(): string
    {
        return 'migrations';
    }

    public function check(): HealthProbeResult
    {
        try {
            $executed = $this->migrations->getMetadataStorage()->getExecutedMigrations();
            $available = $this->migrations->getMigrationPlanCalculator()->getMigrations();

            $pending = 0;
            foreach ($available->getItems() as $migration) {
                if (!$executed->hasMigration($migration->getVersion())) {
                    ++$pending;
                }
            }

            return 0 === $pending
                ? HealthProbeResult::up()
                : HealthProbeResult::down(\sprintf('%d migration(s) pending', $pending));
        } catch (Throwable $e) {
            return HealthProbeResult::down($e::class);
        }
    }
}

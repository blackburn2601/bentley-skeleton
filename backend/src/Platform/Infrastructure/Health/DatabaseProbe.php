<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Health;

use App\Platform\Application\HealthProbe;
use App\Platform\Application\HealthProbeResult;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Is Postgres reachable and answering?
 */
final readonly class DatabaseProbe implements HealthProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthProbeResult
    {
        try {
            // A real round trip. `isConnected()` only reports whether a handle exists, which
            // stays true after the server has gone away.
            $this->connection->executeQuery('SELECT 1')->free();

            return HealthProbeResult::up();
        } catch (Throwable $e) {
            // The class name only: an exception message here can carry the DSN, and this
            // endpoint is unauthenticated.
            return HealthProbeResult::down($e::class);
        }
    }
}

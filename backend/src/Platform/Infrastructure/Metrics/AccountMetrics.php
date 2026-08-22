<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Metrics;

use App\Platform\Application\Metric;
use App\Platform\Application\MetricsSource;
use Doctrine\DBAL\Connection;

/**
 * The numbers worth alerting on.
 *
 * Chosen for what they tell you during an incident rather than for what is easy to count:
 * a spike in `refresh_token_reuse` means tokens are being stolen or a client is refreshing
 * concurrently, and a spike in lockouts means someone is guessing passwords. Both are visible
 * here minutes before anyone reads the audit log.
 */
final readonly class AccountMetrics implements MetricsSource
{
    public function __construct(private Connection $connection)
    {
    }

    public function metrics(): array
    {
        return [
            Metric::gaugeWithLabels(
                'bentley_users_total',
                'Registered accounts by status.',
                $this->countBy('SELECT status, COUNT(*) AS n FROM "user" GROUP BY status', 'status'),
            ),
            Metric::gauge(
                'bentley_accounts_locked',
                'Accounts currently locked by failed login attempts.',
                $this->scalar('SELECT COUNT(*) FROM "user" WHERE locked_until > NOW()'),
            ),
            Metric::gauge(
                'bentley_sessions_active',
                'Refresh tokens that are live: not used, not revoked, not expired.',
                $this->scalar(
                    'SELECT COUNT(*) FROM refresh_token '
                    .'WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > NOW()',
                ),
            ),
            Metric::gaugeWithLabels(
                'bentley_security_events_last_hour',
                'Security events in the last hour, by type.',
                $this->countBy(
                    'SELECT type, COUNT(*) AS n FROM security_event '
                    ."WHERE occurred_at > NOW() - INTERVAL '1 hour' GROUP BY type",
                    'type',
                ),
            ),
        ];
    }

    /**
     * @return array<string, float> Prometheus label string => value
     */
    private function countBy(string $sql, string $labelName): array
    {
        $samples = [];

        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $rawLabel = $row[$labelName] ?? null;
            $label = \is_scalar($rawLabel) ? (string) $rawLabel : 'unknown';
            // Escaped per the exposition format: a stray quote makes the whole scrape
            // unparseable, not just this line.
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $label);
            $count = $row['n'] ?? 0;
            $samples[\sprintf('%s="%s"', $labelName, $escaped)] = is_numeric($count) ? (float) $count : 0.0;
        }

        return $samples;
    }

    private function scalar(string $sql): float
    {
        $value = $this->connection->fetchOne($sql);

        return is_numeric($value) ? (float) $value : 0.0;
    }
}

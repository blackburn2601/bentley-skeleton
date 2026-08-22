<?php

declare(strict_types=1);

namespace App\Api\Platform\Response;

/**
 * Response view for GET /health/ready.
 */
final readonly class ReadinessResponse
{
    /**
     * @param array<string, array{status: string, detail?: string}> $checks
     */
    private function __construct(
        public string $status,
        public array $checks,
    ) {
    }

    /**
     * @param array{ready: bool, checks: array<string, array{status: string, detail?: string}>} $result
     */
    public static function from(array $result): self
    {
        return new self($result['ready'] ? 'ready' : 'not_ready', $result['checks']);
    }
}

<?php

declare(strict_types=1);

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

/**
 * @param array<string, mixed> $context
 */
return static function (array $context): Kernel {
    $env = $context['APP_ENV'] ?? 'dev';
    $debug = $context['APP_DEBUG'] ?? false;

    // PHPStan runs with checkImplicitMixed, so these are narrowed rather than cast:
    // a malformed APP_ENV should fall back to a known-safe value, not become "Array".
    return new Kernel(
        is_string($env) ? $env : 'dev',
        (bool) filter_var($debug, \FILTER_VALIDATE_BOOL),
    );
};

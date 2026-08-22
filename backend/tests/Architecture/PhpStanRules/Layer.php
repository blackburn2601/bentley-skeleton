<?php

declare(strict_types=1);

namespace App\Tests\Architecture\PhpStanRules;

/**
 * Which architectural layer a file belongs to.
 *
 * Layer membership is decided by path, exactly as deptrac.yaml decides it. Keeping the
 * two in agreement matters: if PHPStan and deptrac disagreed about what "the Application
 * layer" means, one of them would be silently enforcing nothing.
 */
final class Layer
{
    private const string SEP = \DIRECTORY_SEPARATOR;

    public static function isApi(string $file): bool
    {
        return self::contains($file, ['src', 'Api']);
    }

    public static function isApplication(string $file): bool
    {
        return self::contains($file, ['Application']) && self::inSrc($file);
    }

    /** An Application *service* specifically, not a facade or a port interface. */
    public static function isApplicationService(string $file): bool
    {
        return self::contains($file, ['Application', 'Service']) && self::inSrc($file);
    }

    public static function isDomain(string $file): bool
    {
        return self::contains($file, ['Domain']) && self::inSrc($file);
    }

    public static function isInfrastructure(string $file): bool
    {
        return self::contains($file, ['Infrastructure']) && self::inSrc($file);
    }

    private static function inSrc(string $file): bool
    {
        return self::contains($file, ['src']);
    }

    /** @param list<string> $segments */
    private static function contains(string $file, array $segments): bool
    {
        $needle = self::SEP.implode(self::SEP, $segments).self::SEP;

        return str_contains(str_replace('/', self::SEP, $file), $needle);
    }
}

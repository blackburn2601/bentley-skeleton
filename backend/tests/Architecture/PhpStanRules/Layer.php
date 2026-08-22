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

    /**
     * Application/Service/ specifically — where the `final readonly` and `*Service` naming
     * rules apply.
     */
    public static function isApplicationService(string $file): bool
    {
        return self::contains($file, ['Application', 'Service']) && self::inSrc($file);
    }

    /**
     * Anything under a context's Application/, at any depth.
     *
     * Broader than isApplicationService() on purpose. `@responsibility` is what
     * docs/SERVICES.md is generated from, and the classes a newcomer most needs to find —
     * PermissionResolver, AclFacade — do not live in Application/Service/. Scoping the rule
     * to that one directory would leave the most important entries out of the inventory,
     * which is the opposite of what it is for.
     */
    public static function isApplicationClass(string $file): bool
    {
        return self::isApplication($file);
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

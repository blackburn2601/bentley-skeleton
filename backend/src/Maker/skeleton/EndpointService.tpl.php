<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

/**
 * @responsibility <?php echo $responsibility; ?>.
 */
final readonly class <?php echo $class_name; ?>

{
    public function __construct(
        // Constructor injection only. No mutable state, no service locator, no container.
    ) {
    }

    /**
     * Takes scalars and value objects, never the HTTP request DTO: that DTO lives in the
     * Api layer, and Application is not allowed to depend on Api (deptrac enforces it).
     * The controller does the mapping — that is the whole of its job.
     *
     * @return array<string, mixed>
     */
    public function __invoke(string $example): array
    {
        throw new \LogicException('<?php echo $class_name; ?> is not implemented yet.');
    }
}

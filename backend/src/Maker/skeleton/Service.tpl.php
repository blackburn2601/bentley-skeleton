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

    public function __invoke(): void
    {
        throw new \LogicException('<?php echo $class_name; ?> is not implemented yet.');
    }
}

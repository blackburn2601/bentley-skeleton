<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

/**
 * Response view for <?php echo $http_method; ?> <?php echo $path; ?>.
 *
 * Entities are never serialized directly (INV-05). Every field a client sees is listed
 * here on purpose, so adding a column to an entity cannot silently start leaking it.
 */
final readonly class <?php echo $class_name; ?>

{
    public function __construct(
        public string $example,
    ) {
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function from(array $result): self
    {
        throw new \LogicException('Map the service result onto <?php echo $class_name; ?>.');
    }
}

<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for <?php echo $http_method; ?> <?php echo $path; ?>.
 *
 * Validation lives here, not in the controller and not in the service: the service is
 * entitled to assume its input is structurally valid, and #[MapRequestPayload] turns a
 * violation into a 422 problem+json before the controller runs.
 */
final readonly class <?php echo $class_name; ?>

{
    public function __construct(
        #[Assert\NotBlank]
        public string $example = '',
    ) {
    }
}

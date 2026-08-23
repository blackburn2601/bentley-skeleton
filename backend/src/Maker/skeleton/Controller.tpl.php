<?php echo "<?php\n"; ?>

declare(strict_types=1);

namespace <?php echo $namespace; ?>;

use <?php echo $request_fqcn; ?>;
use <?php echo $response_fqcn; ?>;
use <?php echo $service_fqcn; ?>;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\<?php echo $payload_attribute; ?>;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * <?php echo $http_method; ?> <?php echo $path; ?>

 *
 * A DTO in, one permission check, one service call, one response view out.
 * Anything more than that belongs in <?php echo $service_short; ?>.
 */
#[Route('<?php echo $path; ?>', name: '<?php echo $route_name; ?>', methods: ['<?php echo $http_method; ?>'])]
#[IsGranted('<?php echo $permission; ?>')]
final class <?php echo $class_name; ?>

{
    public function __construct(
        private readonly <?php echo $service_short; ?> $<?php echo $service_var; ?>,
    ) {
    }

    public function __invoke(
        #[<?php echo $payload_attribute; ?>] <?php echo $request_short; ?> $request,
    ): JsonResponse {
        // Map the request DTO onto the service's parameters here. The service must not
        // receive the DTO itself — Application may not depend on Api.
        $result = ($this-><?php echo $service_var; ?>)($request->example);

        return new JsonResponse(<?php echo $response_short; ?>::from($result), JsonResponse::HTTP_OK);
    }
}

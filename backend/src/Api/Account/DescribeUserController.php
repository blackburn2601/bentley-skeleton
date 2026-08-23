<?php

declare(strict_types=1);

namespace App\Api\Account;

use App\Account\Application\Service\DescribeUserService;
use App\Api\Account\Response\DescribeUserResponse;
use App\Api\Attribute\RateLimit;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/admin/users/{id}.
 */
#[Route(
    '/api/v1/admin/users/{id}',
    name: 'get_describe_user',
    requirements: ['id' => Requirement::UUID],
    methods: ['GET'],
)]
#[IsGranted('user.read')]
#[RateLimit(policy: 'api_user', keyedBy: 'user')]
final readonly class DescribeUserController
{
    public function __construct(private DescribeUserService $describeUser)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        return new JsonResponse(DescribeUserResponse::from(($this->describeUser)(Uuid::fromString($id))));
    }
}

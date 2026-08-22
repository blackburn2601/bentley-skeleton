<?php

declare(strict_types=1);

namespace App\Api\Listener;

use App\Shared\Domain\DomainProblem;
use App\Shared\Domain\ProblemKind;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * The single place that turns an exception into an HTTP response (ADR-0007, INV-17).
 *
 * Every non-2xx body in this API is RFC 9457 `application/problem+json`, so a client parses
 * one shape rather than guessing per endpoint. Because this is the only mapper, changing how
 * a failure is reported is a change to one file.
 *
 * Two rules govern what reaches the caller:
 *
 *  1. **Domain exceptions carry a kind, not a status code.** The Domain says what went wrong;
 *     this decides the number. That keeps services callable outside HTTP (INV-08).
 *  2. **Unclassified exceptions become a bare 500.** The message is logged, never returned.
 *     An unexpected exception's message routinely contains a query, a file path or a
 *     connection string, and an error body is the easiest place in an application to leak one.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final readonly class ProblemJsonExceptionListener
{
    private const string TYPE_BASE = 'https://datatracker.ietf.org/doc/html/rfc9457';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // Leave non-API routes alone: the profiler and the API docs render HTML, and turning
        // their errors into JSON would make them undebuggable.
        if (!str_starts_with($request->getPathInfo(), '/api/') && !str_starts_with($request->getPathInfo(), '/health/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $requestId = $request->attributes->get('_request_id');

        $event->setResponse($this->toResponse($throwable, \is_string($requestId) ? $requestId : null, $request->getPathInfo()));
    }

    private function toResponse(Throwable $throwable, ?string $requestId, string $instance): JsonResponse
    {
        $problem = match (true) {
            $throwable instanceof ValidationFailedException => $this->fromValidation($throwable),
            $throwable instanceof DomainProblem => $this->fromDomain($throwable),
            $throwable instanceof AccessDeniedException => [
                'status' => Response::HTTP_FORBIDDEN,
                'title' => 'Forbidden',
                'detail' => 'You do not have permission to perform this action.',
            ],
            $throwable instanceof HttpExceptionInterface => [
                'status' => $throwable->getStatusCode(),
                'title' => Response::$statusTexts[$throwable->getStatusCode()] ?? 'Error',
                // A framework HttpException message is written for a client (404, 405, 415).
                'detail' => $throwable->getMessage(),
            ],
            default => $this->fromUnexpected($throwable, $requestId),
        };

        $body = [
            'type' => self::TYPE_BASE,
            'title' => $problem['title'],
            'status' => $problem['status'],
            'detail' => $problem['detail'],
            'instance' => $instance,
        ];

        if (isset($problem['errors'])) {
            $body['errors'] = $problem['errors'];
        }

        if (isset($problem['context']) && [] !== $problem['context']) {
            $body += $problem['context'];
        }

        if (null !== $requestId) {
            // The thread to pull: this same id is on the log line and the audit row.
            $body['requestId'] = $requestId;
        }

        $response = new JsonResponse($body, $problem['status']);
        $response->headers->set('Content-Type', 'application/problem+json');

        if (isset($problem['context']['retryAfter']) && \is_string($problem['context']['retryAfter'])) {
            $response->headers->set('Retry-After', $problem['context']['retryAfter']);
        }

        return $response;
    }

    /**
     * @return array{status: int, title: string, detail: string, errors: list<array{field: string, message: string}>}
     */
    private function fromValidation(ValidationFailedException $exception): array
    {
        $errors = [];

        foreach ($exception->getViolations() as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return [
            // 422, not 400: the request was syntactically valid JSON that we understood and
            // could not process. 400 is for a body we could not parse at all.
            'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'title' => 'Validation failed',
            'detail' => 'The request body did not pass validation.',
            'errors' => $errors,
        ];
    }

    /**
     * @return array{status: int, title: string, detail: string, context: array<string, mixed>}
     */
    private function fromDomain(DomainProblem $problem): array
    {
        $status = match ($problem->kind()) {
            ProblemKind::Invalid => Response::HTTP_UNPROCESSABLE_ENTITY,
            ProblemKind::Unauthenticated => Response::HTTP_UNAUTHORIZED,
            ProblemKind::Forbidden => Response::HTTP_FORBIDDEN,
            ProblemKind::NotFound => Response::HTTP_NOT_FOUND,
            ProblemKind::Conflict => Response::HTTP_CONFLICT,
            ProblemKind::TooManyRequests => Response::HTTP_TOO_MANY_REQUESTS,
        };

        return [
            'status' => $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            // Safe by construction: domain exception messages are written for the caller, and
            // the named constructors are where that wording is decided.
            'detail' => $problem->getMessage(),
            'context' => $problem->context(),
        ];
    }

    /**
     * @return array{status: int, title: string, detail: string}
     */
    private function fromUnexpected(Throwable $throwable, ?string $requestId): array
    {
        $this->logger->error('Unhandled exception', [
            'exception' => $throwable,
            'requestId' => $requestId,
        ]);

        return [
            'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
            'title' => 'Internal Server Error',
            // Deliberately generic. The detail is in the log, keyed by requestId.
            'detail' => 'Something went wrong. Quote the request id if you contact support.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Api\Listener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Refuses request bodies this API will not process.
 *
 * Three separate limits, because they stop three different things:
 *
 *  - **Size.** A large body is memory and parse time spent before any authorization check
 *    runs. Cheap for an attacker to send, expensive for us to reject late.
 *  - **JSON depth.** Deeply nested JSON is a classic resource exhaustion: PHP's parser
 *    recurses, and the cost is superlinear in nesting for a body that stays small.
 *  - **Content-Type.** Only JSON is accepted, and form encodings are refused outright: a form
 *    POST is the shape a cross-site form submission takes, and this API has no reason to
 *    accept one.
 */
#[AsEventListener(event: RequestEvent::class, priority: 16)]
final readonly class PayloadLimitSubscriber
{
    private const int MAX_BODY_BYTES = 1_048_576;

    private const int MAX_JSON_DEPTH = 32;

    /** @var list<string> */
    private const array REFUSED_CONTENT_TYPES = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ];

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $problem = $this->inspect($request);

        if (null !== $problem) {
            $event->setResponse($this->refuse($problem[0], $problem[1], $request->getPathInfo()));
        }
    }

    /**
     * @return array{int, string}|null status and detail, or null if acceptable
     */
    private function inspect(Request $request): ?array
    {
        $contentType = strtolower((string) $request->headers->get('Content-Type'));

        foreach (self::REFUSED_CONTENT_TYPES as $refused) {
            if (str_starts_with($contentType, $refused)) {
                return [
                    Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
                    'This API accepts application/json only. Form encodings are not accepted.',
                ];
            }
        }

        $body = $request->getContent();

        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return [
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
                \sprintf('The request body must be at most %d bytes.', self::MAX_BODY_BYTES),
            ];
        }

        if ('' !== $body && str_starts_with($contentType, 'application/json')) {
            json_decode($body, true, self::MAX_JSON_DEPTH);

            if (\JSON_ERROR_DEPTH === json_last_error()) {
                return [
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    \sprintf('The request body is nested more than %d levels deep.', self::MAX_JSON_DEPTH),
                ];
            }
        }

        return null;
    }

    private function refuse(int $status, string $detail, string $instance): JsonResponse
    {
        $response = new JsonResponse([
            'type' => 'https://datatracker.ietf.org/doc/html/rfc9457',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
        ], $status);

        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}

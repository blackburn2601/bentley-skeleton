<?php

declare(strict_types=1);

namespace App\Api\Listener;

use App\Shared\Domain\SecretGenerator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Gives every request an id, and puts it everywhere.
 *
 * The id appears on the log lines, in the problem+json error body the user saw, in the audit
 * row, and in the response header. That single thread is what turns "it broke this morning"
 * into a query — which is why the support instruction in the 500 response is "quote the
 * request id".
 *
 * An inbound `X-Request-Id` is honoured so a trace survives across services, but it is
 * sanitised first: it ends up in log files and response headers, so an unfiltered value is a
 * log-injection and header-splitting vector.
 */
#[AsEventListener(event: RequestEvent::class, priority: 4096)]
#[AsEventListener(event: ResponseEvent::class, method: 'onResponse')]
final readonly class RequestIdSubscriber
{
    public const string HEADER = 'X-Request-Id';
    public const string ATTRIBUTE = '_request_id';

    private const int MAX_LENGTH = 64;

    public function __construct(private SecretGenerator $secrets)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $inbound = $request->headers->get(self::HEADER);

        $request->attributes->set(self::ATTRIBUTE, $this->sanitise($inbound) ?? $this->secrets->generate(16));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (\is_string($requestId)) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }

    /**
     * Accept only what is safe to echo and to log.
     *
     * Rejecting rather than escaping: a caller-supplied id has no business containing
     * anything but an identifier, and silently mangling it would produce a trace id that
     * matches nothing upstream.
     */
    private function sanitise(?string $value): ?string
    {
        if (null === $value || '' === $value || \strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return 1 === preg_match('/^[A-Za-z0-9._\-]+$/', $value) ? $value : null;
    }
}

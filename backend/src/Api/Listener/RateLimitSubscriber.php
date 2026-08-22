<?php

declare(strict_types=1);

namespace App\Api\Listener;

use App\Api\Attribute\RateLimit;
use App\Api\Security\AuthenticatedUser;
use App\Api\Security\RateLimiterRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Applies the `#[RateLimit]` policy declared on a controller.
 *
 * Runs on the CONTROLLER event rather than the request event, because that is the first
 * moment the attribute is readable — and it is still before the controller executes, so a
 * limited request costs nothing beyond routing.
 *
 * Every response carries `X-RateLimit-*`, not just the rejections: a client that can see its
 * remaining budget can slow down, whereas one that only learns on a 429 has to back off
 * blindly.
 */
#[AsEventListener(event: ControllerEvent::class, priority: -8)]
#[AsEventListener(event: ResponseEvent::class, method: 'onResponse')]
final readonly class RateLimitSubscriber
{
    private const string ATTRIBUTE = '_rate_limit_result';

    public function __construct(
        private RateLimiterRegistry $limiters,
        private TokenStorageInterface $tokens,
        private bool $enabled = true,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $attribute = $event->getAttributes(RateLimit::class)[0] ?? null;

        if (!$attribute instanceof RateLimit) {
            return;
        }

        $factory = $this->limiters->get($attribute->policy);

        if (!$factory instanceof RateLimiterFactoryInterface) {
            // An endpoint naming a policy that does not exist is a configuration bug, but it
            // must not take the endpoint down. It is caught by the architecture test that
            // asserts every declared policy is configured.
            return;
        }

        $limit = $factory->create($this->keyFor($attribute, $event->getRequest()))->consume();

        $event->getRequest()->attributes->set(self::ATTRIBUTE, $limit);

        if (!$limit->isAccepted()) {
            $event->setController(fn (): JsonResponse => $this->refuse($limit, $event->getRequest()->getPathInfo()));
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);

        if (!$limit instanceof \Symfony\Component\RateLimiter\RateLimit) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
        $headers->set('X-RateLimit-Remaining', (string) $limit->getRemainingTokens());
        $headers->set('X-RateLimit-Reset', (string) $limit->getRetryAfter()->getTimestamp());
    }

    /**
     * What the limit counts against.
     *
     * `ip+payload` is the important one. Keyed on IP alone, an attacker rotates addresses and
     * keeps guessing one account; keyed on the email alone, they spray many accounts from one
     * host. Combining them closes both, which is why `login` uses it.
     *
     * The payload value is hashed, so the limiter's storage never holds an email address in
     * plaintext — a Redis dump should not be a user list.
     */
    private function keyFor(RateLimit $attribute, Request $request): string
    {
        // getClientIp() honours trusted proxies. Misconfigure those and every caller shares
        // the load balancer's address, so one user exhausts everyone's budget — the first
        // thing docs/OPERATIONS.md tells you to check.
        $ip = $request->getClientIp() ?? 'unknown';

        return match ($attribute->keyedBy) {
            'user' => 'user:'.$this->currentUserId($request),
            'ip+payload' => \sprintf('ip:%s|f:%s', $ip, hash('xxh128', $this->payloadValue($request, $attribute->payloadField))),
            default => 'ip:'.$ip,
        };
    }

    private function currentUserId(Request $request): string
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof AuthenticatedUser
            ? $user->getUserIdentifier()
            : 'anon:'.($request->getClientIp() ?? 'unknown');
    }

    private function payloadValue(Request $request, ?string $field): string
    {
        if (null === $field) {
            return '';
        }

        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            return '';
        }

        $value = $decoded[$field] ?? '';

        // Lower-cased so Alice@example.com and alice@example.com share one bucket; otherwise
        // changing the capitalisation resets the counter.
        return \is_string($value) ? mb_strtolower($value) : '';
    }

    private function refuse(\Symfony\Component\RateLimiter\RateLimit $limit, string $instance): JsonResponse
    {
        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        $response = new JsonResponse([
            'type' => 'https://datatracker.ietf.org/doc/html/rfc9457',
            'title' => 'Too Many Requests',
            'status' => Response::HTTP_TOO_MANY_REQUESTS,
            'detail' => \sprintf('Too many requests. Try again in %d seconds.', $retryAfter),
            'instance' => $instance,
        ], Response::HTTP_TOO_MANY_REQUESTS);

        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('Retry-After', (string) $retryAfter);

        return $response;
    }
}

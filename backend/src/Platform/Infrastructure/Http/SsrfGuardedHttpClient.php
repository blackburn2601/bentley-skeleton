<?php

declare(strict_types=1);

namespace App\Platform\Infrastructure\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * An HTTP client that refuses to be pointed at our own infrastructure.
 *
 * Server-side request forgery turns any feature that fetches a URL into a way to reach things
 * only the server can reach: the database, Redis, the container network, and on a cloud host
 * the instance metadata endpoint — which hands out credentials to anyone who asks from
 * inside.
 *
 * Two layers, because either alone is bypassable:
 *
 *  1. **An egress allow-list**, when configured. Nothing else is reachable at all. This is the
 *     real control; the rest is defence for when the list is deliberately open.
 *  2. **Resolve-then-check.** The hostname is resolved and every address it yields is checked
 *     against private, loopback, link-local and unique-local ranges. A hostname check alone is
 *     useless: `evil.example.com` can simply have an A record of 169.254.169.254.
 *
 * Redirects are followed by the transport, not by us, so `max_redirects` is forced to 0 and a
 * redirect is surfaced instead — otherwise a permitted host could bounce us to a forbidden one
 * after the check has passed. Callers that legitimately need redirects must re-issue the
 * request, which puts each hop through this guard.
 */
final readonly class SsrfGuardedHttpClient implements HttpClientInterface
{
    /**
     * @param list<string> $allowedHosts exact hostnames; empty means "no allow-list, rely on
     *                                   the address checks"
     */
    public function __construct(
        private HttpClientInterface $inner,
        private LoggerInterface $logger,
        private array $allowedHosts = [],
    ) {
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->assertAllowed($url);

        // The transport must not follow a redirect: the destination would not have been
        // checked. See the class docblock.
        $options['max_redirects'] = 0;

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /**
     * @param array<array-key, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->logger, $this->allowedHosts);
    }

    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        // file://, gopher://, dict:// and friends are the classic SSRF escalation from
        // "fetch a URL" to "read /etc/passwd".
        if (!\in_array($scheme, ['http', 'https'], true)) {
            $this->refuse($url, \sprintf('scheme "%s" is not permitted', $scheme));
        }

        if ('' === $host) {
            $this->refuse($url, 'the URL has no host');
        }

        if ([] !== $this->allowedHosts && !\in_array(strtolower($host), $this->allowedHosts, true)) {
            $this->refuse($url, \sprintf('host "%s" is not in the egress allow-list', $host));
        }

        foreach ($this->resolve($host) as $address) {
            if (!$this->isPublicAddress($address)) {
                $this->refuse($url, \sprintf('host "%s" resolves to the non-public address %s', $host, $address));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        // A literal address needs no lookup — and must still be checked.
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        // dns_get_record emits a warning for an unresolvable name rather than only returning
        // false. Converting warnings to exceptions for this one call keeps the failure on the
        // return path without suppressing it with @, which hides genuine errors too.
        set_error_handler(static fn (): bool => true);

        try {
            $records = dns_get_record($host, \DNS_A | \DNS_AAAA);
        } finally {
            restore_error_handler();
        }

        if (false === $records || [] === $records) {
            // Refusing on a failed lookup rather than allowing: an unresolvable host cannot be
            // fetched anyway, and "we could not check" must never mean "so we allowed it".
            $this->refuse($host, 'the hostname could not be resolved');
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (\is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        return false !== filter_var(
            $address,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );
    }

    private function refuse(string $url, string $reason): never
    {
        // Logged, because a blocked egress attempt is either a bug or an attack, and both are
        // worth seeing. The URL is logged; the reason is not returned to any caller.
        $this->logger->warning('Blocked outbound request', ['url' => $url, 'reason' => $reason]);

        throw new TransportException(\sprintf('Outbound request refused: %s.', $reason));
    }
}

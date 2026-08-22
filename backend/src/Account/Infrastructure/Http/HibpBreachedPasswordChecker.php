<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Http;

use App\Account\Application\BreachedPasswordChecker;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Have I Been Pwned, via k-anonymity.
 *
 * **The password never leaves this process.** Only the first five characters of its SHA-1 are
 * sent; the service returns every suffix sharing that prefix (typically a few hundred), and
 * the comparison happens here. So HIBP learns a prefix that matches millions of passwords and
 * nothing else.
 *
 * SHA-1 is not a security choice — it is the hash HIBP's corpus is indexed by. It is used
 * here purely as a lookup key against a public dataset, never to store anything.
 */
final readonly class HibpBreachedPasswordChecker implements BreachedPasswordChecker
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private bool $enabled = true,
        private float $timeoutSeconds = 2.0,
    ) {
    }

    public function isBreached(string $password): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT.$prefix, [
                'timeout' => $this->timeoutSeconds,
                // Pads the response with random hashes so its size does not narrow down which
                // prefix was requested to anyone watching the connection.
                'headers' => ['Add-Padding' => 'true'],
            ]);

            $body = $response->getContent();
        } catch (Throwable $e) {
            // Fail OPEN, deliberately. See BreachedPasswordChecker: making registration depend
            // on a third party being up trades a small hardening win for a real availability
            // risk. Logged so the silence is visible in monitoring.
            $this->logger->warning('Breached-password check unavailable; allowing the password.', [
                'exception' => $e::class,
            ]);

            return false;
        }

        return array_any(explode("\n", $body), static fn ($line): bool => str_starts_with($line, $suffix.':'));
    }
}

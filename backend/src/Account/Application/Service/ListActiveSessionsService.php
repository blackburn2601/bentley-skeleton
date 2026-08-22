<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\RefreshToken;
use App\Account\Domain\RefreshTokenRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TokenHash;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Lists the sessions a user currently has open.
 */
final readonly class ListActiveSessionsService
{
    public function __construct(
        private RefreshTokenRepository $tokens,
        private Clock $clock,
    ) {
    }

    /**
     * @param string|null $currentToken the caller's own refresh token, so the UI can mark
     *                                  which row is "this device" — matched by hash, never stored or returned
     *
     * @return list<array{id: string, createdAt: string, ipAddress: string|null, userAgent: string|null, current: bool}>
     */
    public function __invoke(Uuid $userId, ?string $currentToken = null): array
    {
        $currentHash = null === $currentToken || '' === $currentToken
            ? null
            : TokenHash::of($currentToken)->value;

        return array_map(
            static function (RefreshToken $token) use ($currentHash): array {
                return [
                    // The FAMILY id, not the token id: a family is one session, and the token
                    // within it changes on every refresh. Showing token ids would make one
                    // device look like hundreds of sessions.
                    'id' => $token->familyId()->toRfc4122(),
                    'createdAt' => $token->createdAt()->format(\DATE_ATOM),
                    'ipAddress' => $token->ipAddress(),
                    'userAgent' => $token->userAgent(),
                    'current' => null !== $currentHash && $token->matchesHash($currentHash),
                ];
            },
            $this->tokens->findActiveSessionsForUser($userId, $this->clock->now()),
        );
    }
}

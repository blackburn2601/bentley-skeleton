<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Application\AccessTokenIssuer;
use App\Account\Application\TwoFactorChallenge;
use App\Account\Domain\User;

/**
 * @responsibility Mints the short-lived challenge token for a pending second factor.
 */
final readonly class IssueTwoFactorChallengeService
{
    public function __construct(private AccessTokenIssuer $accessTokens)
    {
    }

    public function __invoke(User $user): TwoFactorChallenge
    {
        return new TwoFactorChallenge(
            userId: $user->id()->toRfc4122(),
            username: $user->username(),
            accessToken: $this->accessTokens->issueChallenge($user->id(), $user->username(), $user->aclVersion()),
            // The cookie Max-Age must match the token's real expiry (the short challenge TTL),
            // not the normal access TTL — otherwise the cookie outlives the token and the
            // browser keeps sending a token that no longer decodes.
            accessTtlSeconds: $this->accessTokens->challengeTtlSeconds(),
        );
    }
}

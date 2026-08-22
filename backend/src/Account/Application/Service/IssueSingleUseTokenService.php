<?php

declare(strict_types=1);

namespace App\Account\Application\Service;

use App\Account\Domain\SingleUseToken;
use App\Account\Domain\SingleUseTokenRepository;
use App\Account\Domain\TokenPurpose;
use App\Account\Domain\User;
use App\Shared\Domain\Clock;
use App\Shared\Domain\SecretGenerator;
use App\Shared\Domain\TokenHash;

/**
 * @responsibility Mints a one-time secret for an email-delivered action.
 */
final readonly class IssueSingleUseTokenService
{
    public function __construct(
        private SingleUseTokenRepository $tokens,
        private SecretGenerator $secrets,
        private Clock $clock,
    ) {
    }

    /**
     * Returns the PLAINTEXT token — the only moment it exists in this system.
     *
     * Only its hash is stored, so a database backup does not hand over the ability to verify
     * or reset every pending account. The caller must put this straight into an email and
     * keep it out of logs.
     *
     * Any outstanding token for the same purpose is consumed first, so a fresh "reset my
     * password" link silently invalidates the previous one — otherwise every reset ever
     * requested stays live until it expires.
     */
    public function __invoke(User $user, TokenPurpose $purpose): string
    {
        $now = $this->clock->now();

        $this->tokens->consumeOutstanding($user, $purpose, $now);

        $plaintext = $this->secrets->generate();

        $this->tokens->save(new SingleUseToken(
            TokenHash::of($plaintext)->value,
            $user,
            $purpose,
            $now,
            $now->add($purpose->ttl()),
        ));

        return $plaintext;
    }
}

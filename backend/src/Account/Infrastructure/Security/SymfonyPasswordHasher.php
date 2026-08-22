<?php

declare(strict_types=1);

namespace App\Account\Infrastructure\Security;

use App\Account\Application\PasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * argon2id, configured in config/packages/security.yaml.
 */
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    /**
     * The name this hasher is configured under in security.yaml.
     *
     * A NAME rather than a user class: Symfony's factory resolves class keys through the user
     * hierarchy, and nothing here implements UserInterface — passwords belong to the Account
     * domain's User, which is deliberately not the security-layer user (see AuthenticatedUser).
     */
    public const string HASHER_NAME = 'app_account_password';

    private PasswordHasherInterface $hasher;

    public function __construct(PasswordHasherFactoryInterface $factory)
    {
        // Cost parameters live in security.yaml, in one place, next to the reasoning for them.
        $this->hasher = $factory->getPasswordHasher(self::HASHER_NAME);
    }

    public function hash(string $plainPassword): string
    {
        return $this->hasher->hash($plainPassword);
    }

    public function verify(string $hash, string $plainPassword): bool
    {
        // '' is what an anonymised account's hash column holds (see User::anonymise).
        // password_verify against an empty hash returns false anyway, but being explicit
        // keeps the intent visible.
        if ('' === $hash) {
            return false;
        }

        return $this->hasher->verify($hash, $plainPassword);
    }

    public function needsRehash(string $hash): bool
    {
        return '' !== $hash && $this->hasher->needsRehash($hash);
    }
}

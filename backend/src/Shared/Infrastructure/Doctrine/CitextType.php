<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * Postgres `citext` — case-insensitive text.
 *
 * Used for email addresses so that uniqueness and lookup are case-insensitive *in the
 * database*. Lowercasing in PHP is the usual alternative and it works until the one query
 * that forgets, which then registers a second account differing only in capitalisation — at
 * which point "which account does this password reset belong to?" has two answers.
 *
 * The extension is created by docker/postgres/init/10-extensions.sql and by the first
 * migration, so a database built either way has it.
 */
final class CitextType extends Type
{
    public const string NAME = 'citext';

    public function getName(): string
    {
        return self::NAME;
    }

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CITEXT';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string']);
        }

        return $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::NAME, ['null', 'string']);
        }

        return $value;
    }

    /**
     * Without this, every schema diff wants to "change" the column, because DBAL maps the
     * unknown citext type back to TEXT and then sees a difference that is not there.
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}

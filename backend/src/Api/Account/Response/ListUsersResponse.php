<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;
use DateTimeInterface;

/**
 * One page of user accounts, as an administrator's client needs them.
 *
 * Every field is listed by hand (INV-05). `passwordHash` is absent on purpose and must stay
 * absent: serialising the entity would ship it the day someone added a column.
 *
 * The envelope — items, page, perPage, total — is the same on every collection in this API
 * (ADR-0019), so a client writes one pager.
 */
final readonly class ListUsersResponse
{
    /**
     * @param list<array{
     *     id: string,
     *     username: string,
     *     status: string,
     *     lockedUntil: string|null,
     *     createdAt: string,
     * }> $items
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param list<User> $users
     */
    public static function from(array $users, int $page, int $perPage, int $total): self
    {
        return new self(
            array_map(static fn (User $user): array => [
                'id' => $user->id()->toRfc4122(),
                'username' => $user->username(),
                'status' => $user->status()->value,
                'lockedUntil' => $user->lockedUntil()?->format(DateTimeInterface::ATOM),
                'createdAt' => $user->createdAt()->format(DateTimeInterface::ATOM),
            ], $users),
            $page,
            $perPage,
            $total,
        );
    }
}

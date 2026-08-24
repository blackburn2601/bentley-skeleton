<?php

declare(strict_types=1);

namespace App\Account\Domain;

use Symfony\Component\Uid\Uuid;

interface UserRepository
{
    public function findById(Uuid $id): ?User;

    /** Case-insensitive by virtue of the citext column, not by lowercasing here. */
    public function findByUsername(string $username): ?User;

    public function existsByUsername(string $username): bool;

    public function save(User $user): void;

    /** @return list<User> */
    public function findAllPaginated(int $offset, int $limit): array;

    public function countAll(): int;
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Shared\Application\AclVersionProvider;
use Symfony\Component\Uid\Uuid;

final class StubAclVersionProvider implements AclVersionProvider
{
    /** @var array<string, int> */
    private array $versions = [];

    public function versionFor(Uuid $userId): int
    {
        return $this->versions[$userId->toRfc4122()] ?? 1;
    }

    public function bumpAll(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $key = $userId->toRfc4122();
            $this->versions[$key] = ($this->versions[$key] ?? 1) + 1;
        }
    }
}

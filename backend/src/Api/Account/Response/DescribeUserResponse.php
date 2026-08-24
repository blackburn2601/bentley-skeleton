<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

/**
 * One user account, in full, for the administrative detail screen.
 *
 * Grouped into `security` and `access` rather than thirteen flat fields. That started as a
 * complexity limit refusing the constructor, but the nesting is the better contract anyway:
 * "why can this person not sign in?" and "what may this person do?" are two different
 * questions, and the answer to each is now one object rather than a handful of siblings.
 *
 * Still absent, and this is where it matters most: `passwordHash`. A detail view is exactly
 * the endpoint where "just return the entity" is most tempting and most dangerous (INV-05).
 */
final readonly class DescribeUserResponse
{
    /**
     * @param array{failedLoginCount: int, lockedUntil: string|null, passwordChangedAt: string, mfaEnrolled: bool, mfaRequired: bool} $security
     * @param array{roles: list<string>, groups: list<string>, effectivePermissions: list<string>}                                    $access
     */
    private function __construct(
        public string $id,
        public string $username,
        public string $status,
        public string $createdAt,
        /**
         * The counter behind ADR-0011: any grant change bumps it, and the next request
         * recomputes. Exposed because a claim you can watch change is worth more than one in
         * a docblock.
         */
        public int $aclVersion,
        public array $security,
        public array $access,
    ) {
    }

    /**
     * @param array{
     *     id: string, username: string, status: string,
     *     failedLoginCount: int, lockedUntil: string|null, passwordChangedAt: string,
     *     createdAt: string, aclVersion: int,
     *     roles: list<string>, groups: list<string>, effectivePermissions: list<string>,
     *     mfaEnrolled: bool, mfaRequired: bool
     * } $profile
     */
    public static function from(array $profile): self
    {
        return new self(
            $profile['id'],
            $profile['username'],
            $profile['status'],
            $profile['createdAt'],
            $profile['aclVersion'],
            [
                'failedLoginCount' => $profile['failedLoginCount'],
                'lockedUntil' => $profile['lockedUntil'],
                'passwordChangedAt' => $profile['passwordChangedAt'],
                'mfaEnrolled' => $profile['mfaEnrolled'],
                'mfaRequired' => $profile['mfaRequired'],
            ],
            [
                'roles' => $profile['roles'],
                'groups' => $profile['groups'],
                'effectivePermissions' => $profile['effectivePermissions'],
            ],
        );
    }
}

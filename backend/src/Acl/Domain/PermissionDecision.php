<?php

declare(strict_types=1);

namespace App\Acl\Domain;

/**
 * The outcome of a permission check, with the reasoning attached.
 *
 * Per-object ACLs are usually abandoned not because they are wrong but because nobody can
 * answer "why can't this user do this?" in production. Carrying the winning entry and its
 * tier makes that question answerable — see the admin explain endpoint.
 */
final readonly class PermissionDecision
{
    private function __construct(
        public bool $granted,
        public AclTier $tier,
        public ?AclEntry $decidedBy = null,
        public ?string $note = null,
    ) {
    }

    public static function granted(AclTier $tier, ?AclEntry $entry = null, ?string $note = null): self
    {
        return new self(true, $tier, $entry, $note);
    }

    public static function denied(AclTier $tier, ?AclEntry $entry = null, ?string $note = null): self
    {
        return new self(false, $tier, $entry, $note);
    }

    /** A sentence a human can read in an admin screen. */
    public function explain(): string
    {
        return \sprintf(
            '%s by %s%s',
            $this->granted ? 'Granted' : 'Denied',
            $this->tier->describe(),
            null === $this->note ? '' : ' ('.$this->note.')',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Throwable;

/**
 * A domain exception that knows what kind of failure it is.
 *
 * The problem+json listener maps `kind()` to a status code. An exception that does not
 * implement this becomes a 500, which is the honest answer: an unclassified failure is a bug
 * we have not thought about yet, not a client error.
 */
interface DomainProblem extends Throwable
{
    public function kind(): ProblemKind;

    /**
     * Extra machine-readable detail for the response body.
     *
     * Must be safe to show the caller. Nothing here should distinguish "no such account" from
     * "wrong password", or the endpoint becomes an enumeration oracle.
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}

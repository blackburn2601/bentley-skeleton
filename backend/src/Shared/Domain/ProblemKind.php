<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * What kind of failure a domain exception represents.
 *
 * The Domain says *what went wrong*; the Api layer decides which number that becomes
 * (INV-08, INV-17). Without this, either the Domain would carry HTTP status codes — making it
 * uncallable from a console command without pretending to be a request — or the listener
 * would map exceptions by inspecting their messages, which breaks the moment anyone rewords
 * one.
 */
enum ProblemKind: string
{
    /** The request was understood and refused: bad input, an expired token, a broken rule. */
    case Invalid = 'invalid';

    /** No usable credentials were presented. */
    case Unauthenticated = 'unauthenticated';

    /** Credentials were fine; the caller is not allowed to do this. */
    case Forbidden = 'forbidden';

    case NotFound = 'not_found';

    /** The current state of the resource prevents this. */
    case Conflict = 'conflict';

    /** Refused for now — rate limited, or locked out. */
    case TooManyRequests = 'too_many_requests';
}

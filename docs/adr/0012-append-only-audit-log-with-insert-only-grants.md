# 0012. Append-only audit log, enforced with INSERT-only database grants

- **Status:** accepted
- **Date:** 2026-08-22

## Context

Security events — logins, lockouts, token reuse, permission changes, admin data access,
GDPR actions — are only useful as evidence if they cannot be quietly edited. An audit table
the application can UPDATE or DELETE proves nothing after a compromise, because whoever got
in could tidy up behind themselves.

## Decision

`security_event` is append-only. The application's database role is granted INSERT on it and
nothing else. Retention is handled by a separate command running as a different role.

## Consequences

### Positive

- The guarantee holds at the database, not by convention. An attacker with application-level
  code execution still cannot rewrite history.
- Auditors get a straight answer to "could this have been altered?".

### Negative

- Two database roles to provision, and migrations must run as the owner rather than the
  application user.
- Mistakes are permanent: a bad audit row stays. That is the intended trade.
- Retention needs its own privileged path.

## Alternatives rejected and why

- **Application-level "never update" discipline** — unenforceable, and worthless precisely
  in the scenario the log exists for.
- **Doctrine soft-delete on audit rows** — a delete flag is an update; the row can still be
  hidden.
- **Ship logs off-host only** — good practice and complementary, but it makes the audit trail
  depend on a shipping pipeline being healthy at the moment of the incident.

## Reversal cost

**Cheap to relax** (grant UPDATE), **and that relaxation destroys the property**. Any change
here belongs in a new ADR, not a migration.

## Implemented by

- `backend/src/Audit/`
- the migration that issues the grants, and the integration test asserting UPDATE is refused

# 0017. `damienharper/auditor` core library instead of `auditor-bundle`

- **Status:** accepted
- **Date:** 2026-08-22

## Context

Entity change history — who changed which field, when, from what to what — is a compliance
requirement, and `damienharper/auditor-bundle` is the standard Symfony answer. It was the
package originally specified for this skeleton.

Two things ruled it out, found by resolving the dependency graph rather than by assuming it
would work:

1. **It cannot be installed.** Every `auditor-bundle ^7.x` release requires
   `symfony/framework-bundle ^8.0`. There is no 7.x release that accepts Symfony 7.x, and
   this skeleton is on 7.4 LTS (ADR-0005). `composer require` fails outright.
2. **The newest version that *would* install (`^6.3`) is the wrong shape.** It pulls
   `symfony/twig-bundle`, `twig/extra-bundle`, `twig/intl-extra`, `symfony/asset` and
   `symfony/translation` — all to render an HTML audit viewer that a headless API never
   serves.

## Decision

Use the core library `damienharper/auditor ^3.4` directly and wire it by hand in
`src/Audit/Infrastructure/`: `AuditConfiguration`, the Doctrine provider, the storage and
auditing services, and the Doctrine event subscribers. That is the work the bundle would
otherwise do, minus the UI.

## Consequences

### Positive

- Installs on Symfony 7.4, which the bundle simply does not.
- No Twig, asset or translation dependencies dragged into a headless API. The dependency
  graph stays honest about what this application actually does.
- The wiring is visible in our own code, so what is audited and where it is stored is
  readable rather than configured by a bundle extension.

### Negative

- Roughly a provider class plus service configuration that the bundle would have supplied,
  and it is ours to maintain across upgrades.
- No built-in audit viewer. Audit history is exposed through the admin API instead, which is
  where it belongs for a headless application anyway.

## Alternatives rejected and why

- **`auditor-bundle ^7.2`** — cannot be installed on Symfony 7.4. Not a preference.
- **`auditor-bundle ^6.3`** — installable, but see the dependency list above; Twig in a
  headless API for a page nothing renders.
- **Move the skeleton to Symfony 8.1 so the bundle works** — considered and rejected in
  ADR-0005: it trades LTS lifetime for one bundle's convenience.
- **Drop entity auditing, keep only the `SecurityEvent` log** — the security log records
  *events*, not field-level before/after values. Compliance questions ask for the latter.
- **Write our own Doctrine change subscriber** — reinvents a maintained library for no gain.

## Current status — the wiring does not record yet

Recorded here rather than left as a surprise, because a half-working audit trail is worse
than none: it looks like evidence and is not.

**Working:** the configuration builds, the provider registers one auditing and one storage
service, six entities are marked audited, `bin/console app:audit:schema-update` produces the
schema, and the six `*_audit` tables exist via a migration.

**Not working:** no rows are written on flush. Verified at runtime that the auditor is
enabled, that `isAudited(User::class)` is true, that the initializer constructs the Auditor
before any request or command, and that the DBAL driver is wrapped in `AuditorDriver` — the
subscriber's precondition. Rows still do not appear, with or without an explicit transaction.
Something the bundle does during wiring is still missing, and it was not worth more time to
find before the rest of the skeleton was finished.

`AUDIT_ENTITY_HISTORY` therefore defaults to **0**, so the feature is honestly off rather
than silently broken.

**This does not affect the primary compliance control.** The append-only `security_event` log
(ADR-0012) is a separate mechanism, written explicitly by the code that acts, and it is
verified end to end: registration, verification, login success and failure, token rotation and
reuse detection all produce rows with actor, IP and payload.

**Where to look next:** compare against `auditor-bundle`'s `DHAuditorExtension` and its
compiler passes — specifically whether it registers the storage/auditing services *after* the
entity manager is fully built, and whether `TransactionManager::process()` needs a storage
mapper or a flusher hook this wiring omits.

## Reversal cost

**Cheap, once on Symfony 8.x.** Install the bundle, delete our wiring, keep the schema — the
bundle uses the same core library underneath. Revisit alongside ADR-0005.

## Implemented by

- `backend/src/Audit/Infrastructure/`
- `backend/composer.json` (`damienharper/auditor ^3.4`)

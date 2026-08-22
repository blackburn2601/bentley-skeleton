# 0005. Symfony 7.4 LTS on PHP 8.5, rather than Symfony 8.1

- **Status:** accepted
- **Date:** 2026-08-22

## Context

This repository seeds every future project. Its framework version therefore becomes several
projects' framework version, and upgrading it later means upgrading all of them.

At the time of writing Symfony 8.1.5 is current. Symfony 7.4 is the LTS: bug fixes to
November 2028, security fixes to November 2029. Standard releases such as 8.1 get roughly
fourteen months in total.

## Decision

Symfony 7.4 LTS on PHP 8.5.

## Consequences

### Positive

- A project started from this template does not face a major framework upgrade within its
  first year.
- Security support runs to late 2029 without further action.

### Negative

- Some bundles now publish `^8.0`-only majors, so this repo runs the previous major of those
  packages. **`damienharper/auditor-bundle ^7.x` requires `symfony/framework-bundle ^8.0`
  and cannot be installed at all here** — which is why entity auditing uses the core library
  instead (ADR-0017).
- 7.4 misses features shipped in 8.x.
- Moving to 8.x later is a real upgrade, not a constraint bump, because 7.4 → 8.0 crosses a
  major boundary.

## Alternatives rejected and why

- **Symfony 8.1** — every bundle in this stack declares `^8.0` support, so it would install
  cleanly today, and `auditor-bundle ^7.2` would work as originally specified. Rejected on
  lifetime: a standard release forces an upgrade within about a year, repeated across every
  project seeded from this template.
- **Wait for Symfony 8.4 LTS** — that is the next LTS, but it does not exist yet, and the
  skeleton is needed now.
- **Symfony 6.4 LTS** — an older LTS with a nearer end of life and no PHP 8.5 upside.

## Reversal cost

**Moderate.** Moving to 8.x is a standard Symfony major upgrade: bump the constraints, run
Rector's Symfony set, fix deprecations. The auditor decision (ADR-0017) could then be
revisited, since the bundle becomes installable.

## Implemented by

- `backend/composer.json` (`symfony/*: 7.4.*`, `php: >=8.5`)

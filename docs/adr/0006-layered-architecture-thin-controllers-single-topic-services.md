# 0006. Layered architecture, thin controllers, single-topic services

- **Status:** accepted
- **Date:** 2026-08-22

## Context

This repository is a template that AI sessions and new contributors will extend without
having read the whole codebase. The dominant failure mode in that situation is not writing
bad code — it is writing a *second* copy of something that already exists, in the wrong
place, because finding the first copy was harder than writing it again.

Conventions in a CONTRIBUTING file do not survive that. They are read once, or never.

## Decision

Four layers with a single permitted dependency direction (`Api` → `Application` → `Domain`;
`Infrastructure` → `Domain` and `Application` ports), bounded contexts that may only reach
each other through facades, and Application services that are `final readonly` and declare
exactly one semantic topic in a `@responsibility` sentence.

All of it is enforced by tooling — deptrac for direction and contexts, PHPStan rules for
shape, PHPMD for size — and every failure message names the invariant and the fix.

## Consequences

### Positive

- "One topic per service" becomes checkable rather than a matter of taste: if the sentence
  needs an "and", it is two classes.
- `docs/SERVICES.md` is generated from those sentences, so the "does this already exist?"
  question has a cheap answer.
- A contributor cannot accidentally couple two contexts; they have to add a facade method,
  which is visible in review.

### Negative

- More indirection than a small app needs. A three-endpoint service would be faster to write
  flat.
- The rules can feel obstructive before you have read `docs/INVARIANTS.md`, which is why
  every message links to it.

## Alternatives rejected and why

- **Convention documented in CONTRIBUTING.md** — the thing this decision exists to replace.
  Unenforced conventions decay silently, and the decay is invisible until someone audits.
- **A single flat `src/Service/` directory** — works up to about twenty services, then
  becomes a namespace where everything is technically findable and nothing is actually
  found.
- **Hexagonal architecture with ports and adapters everywhere** — the interface-per-class
  ceremony costs more than it returns at this size. INV-12 states the narrower rule:
  interfaces at real port boundaries only.
- **Enforcement in code review only** — reviewers miss layering violations reliably,
  because a violation looks like ordinary code.

## Reversal cost

**Cheap to relax, expensive to re-impose.** Deleting `deptrac*.yaml` and the custom PHPStan
rules removes the enforcement in one commit. Re-imposing it on a codebase that has drifted
means fixing every accumulated violation at once — which is the usual reason teams never
turn it back on.

## Implemented by

- `backend/deptrac.yaml`, `backend/deptrac-context.yaml`
- `backend/tests/Architecture/PhpStanRules/`, `backend/tests/Architecture/PhpatRules/`
- `backend/phpmd-api.xml`
- `bin/strictness-proof`

# 0016. Documentation as code: generated inventories with a CI freshness gate

- **Status:** accepted
- **Date:** 2026-08-22

## Context

This repository is built to be extended by sessions with no prior context. That makes
documentation load-bearing rather than decorative — and stale documentation is worse than
none, because it is trusted.

Hand-maintained lists of services, endpoints and permissions go stale immediately. They go
stale precisely when the codebase is changing fastest, which is when they are most needed.

## Decision

`bin/console app:docs:generate` derives `docs/SERVICES.md`, `docs/ENDPOINTS.md`,
`docs/PERMISSIONS.md` and `docs/adr/README.md` from the code. The generated files are
committed, and `make docs` — plus `.github/workflows/docs.yml` — regenerates and **fails on
any diff**.

Prose that requires judgement (`ARCHITECTURE.md`, `INVARIANTS.md`, the ADRs, the cookbook)
stays hand-written. Only inventories are generated.

## Consequences

### Positive

- `docs/SERVICES.md` lists every service with its `@responsibility` sentence, which is how a
  contributor finds the class that already owns a topic instead of writing a second one.
  That directly serves INV-10.
- The docs cannot drift: drift is a red build with an obvious fix (`make docs`, commit).
- Committing the output means the files are readable on GitHub without running anything.

### Negative

- A merge conflict in a generated file is possible; the resolution is always "regenerate".
- Adding a service and forgetting `make docs` fails CI. Intended, and the failure message
  says exactly what to run.
- The generator itself is code that must be maintained.

## Alternatives rejected and why

- **Hand-written inventories** — the problem being solved.
- **Generate in CI without committing** — then the files are not readable in the repository,
  and a fresh session cannot use them without a build step.
- **Auto-commit regenerated docs from CI** — hides drift instead of surfacing it, and puts
  commits in the history that no human wrote.
- **No inventories, rely on grep** — works if you already know what to grep for, which is
  exactly what a context-free session does not.

## Reversal cost

**Cheap.** Delete the gate and the files become ordinary documentation — and start rotting
the same day.

## Implemented by

- `backend/src/Platform/` (the `app:docs:generate` command)
- `.github/workflows/docs.yml`, the `docs` target in `Makefile`

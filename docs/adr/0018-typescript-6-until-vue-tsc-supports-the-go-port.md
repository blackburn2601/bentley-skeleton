# 0018. TypeScript 6 until `vue-tsc` supports the TypeScript 7 Go port

- **Status:** accepted
- **Date:** 2026-08-22

## Context

TypeScript 7 is current and is the Go rewrite of the compiler. The skeleton was specified to
use it.

It does not work with Vue single-file components today. `vue-tsc` resolves
`typescript/lib/tsc` at startup, and TypeScript 7 no longer exports that subpath, so
`vue-tsc -b` fails immediately:

```
Error [ERR_PACKAGE_PATH_NOT_EXPORTED]:
Package subpath './lib/tsc' is not defined by "exports" in .../typescript/package.json
```

`vue-tsc`'s latest release (3.3.11) has no TypeScript 7 support and there is no preview tag.
This was established by installing TypeScript 7 and running the build, not by reading
version constraints — `vue-tsc`'s peer range is `typescript: >=5.0.0`, which *permits* 7 and
would have suggested it was fine.

## Decision

Pin `typescript: ~6.0.2`. Revisit when Volar supports `tsgo`.

## Consequences

### Positive

- `npm run typecheck` and `npm run build` work, which is the entire point of having a
  typechecker.
- No divergence between the editor's language service and the build.

### Negative

- No TypeScript 7 compile-speed improvement.
- The skeleton is one major version behind on a headline dependency, which will look like an
  oversight to anyone who does not read this ADR. That is why it exists.

## Alternatives rejected and why

- **TypeScript 7 with `vue-tsc`** — does not run.
- **TypeScript 7 and drop SFC type-checking** — `.vue` files would go unchecked. Losing type
  safety on the component layer to gain compiler speed is the wrong trade in a codebase whose
  premise is machine-enforced correctness.
- **TypeScript 7 with `tsc` for `.ts` only, `vue-tsc` skipped** — two typecheckers with
  different views of the project, and the components stay unchecked.

## Reversal cost

**Trivial.** One constraint in `frontend/package.json`, once `vue-tsc` ships support.

## Implemented by

- `frontend/package.json`

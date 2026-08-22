# 0015. Keep the `App\` namespace; project identity lives in package and image names

- **Status:** accepted
- **Date:** 2026-08-22

## Context

A template could rename its PHP root namespace per project — `Acme\Api\…` rather than
`App\…` — so that the code reads as the project's own.

## Decision

The PHP root namespace stays `App\`. Project identity is expressed where it actually matters:
the composer package name, the npm package name, the compose project name, the container
image name, the cookie prefix and the README title. `make new-project NAME=…` rewrites all of
those.

## Consequences

### Positive

- Symfony's own convention, so every tutorial, recipe, maker and Stack Overflow answer
  applies unmodified.
- `make new-project` stays a text substitution over names, not a refactor of every `use`
  statement in the repository.
- Merging improvements from the skeleton back into a derived project stays possible, because
  the namespaces still line up.

### Negative

- Two projects' classes cannot be loaded into one process without a collision — irrelevant
  for applications, and these are applications.
- `App\Acl\Domain\Permission` reads slightly less specifically than `Acme\Acl\Domain\Permission`.

## Alternatives rejected and why

- **Rename per project** — churn with no functional gain: it breaks recipe compatibility,
  makes upstream merges painful, and the class names are already unambiguous within a
  project.
- **Vendor-prefixed namespace in the skeleton itself** (`Bentley\…`) — every derived project
  then either keeps someone else's vendor name or does the rename anyway.

## Reversal cost

**Cheap but noisy.** A find-and-replace plus a composer autoload change touches every file,
which makes the diff useless for review for one commit.

## Implemented by

- `backend/composer.json` (`autoload.psr-4`)
- `bin/new-project`

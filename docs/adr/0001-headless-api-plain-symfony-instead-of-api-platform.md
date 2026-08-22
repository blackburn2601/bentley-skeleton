# 0001. Headless API on plain Symfony with DTOs, not API Platform

- **Status:** accepted
- **Date:** 2026-08-22

## Context

The skeleton needs a JSON API consumed by a Vue SPA and, later, by machine clients. API
Platform is the default answer in the Symfony world: it derives REST endpoints, OpenAPI
documentation, pagination and filtering from entity attributes.

That derivation is the problem. This skeleton's central rule is that endpoints contain no
logic and that every published field is a deliberate choice (INV-05, INV-07). API Platform
works in the opposite direction: the entity is the API, and shaping the contract means
layering serialization groups, custom normalizers, state processors and providers on top of
generated behaviour.

## Decision

Plain Symfony controllers, one class per endpoint with a single `__invoke()`. Request bodies
are DTOs bound with `#[MapRequestPayload]` and validated by the Validator component.
Responses are explicit view classes. OpenAPI comes from NelmioApiDocBundle reading those
same attributes and DTOs.

## Consequences

### Positive

- The API contract is readable in one file per endpoint. What a client receives is a class
  you can open, not the outcome of a normalizer chain.
- Adding a column to an entity cannot change the API by accident.
- The architecture rules can be enforced mechanically, because a controller is an ordinary
  class rather than framework-generated behaviour.

### Negative

- More files per endpoint: controller, request DTO, response view. `make:api-endpoint`
  exists precisely to make that cost near zero.
- CRUD that API Platform gives away is written by hand here.
- Pagination and filtering are ours to build. Deliberately out of scope for the skeleton.

## Alternatives rejected and why

- **API Platform** — rejected above: it inverts the "entity is not the contract" rule and
  makes the no-logic-in-endpoints rule unenforceable, since much of the behaviour is not
  code in this repo.
- **A thin custom framework over the Kernel** — no. Every hour spent there is an hour not
  spent on the ACL, and it makes the codebase unfamiliar to any Symfony developer.
- **Controllers with multiple actions** — one class per endpoint keeps routing,
  authorization and the response shape visible together, and makes "one `#[IsGranted]` per
  endpoint" a checkable property.

## Reversal cost

**Moderate.** API Platform could be added alongside for a new subtree of routes without
touching existing endpoints. Converting existing endpoints would mean rewriting each
controller as a state provider/processor — mechanical, but proportional to endpoint count.

## Implemented by

- `backend/src/Api/` and the shape rules in `backend/tests/Architecture/`
- `backend/src/Maker/ApiEndpointMaker.php`

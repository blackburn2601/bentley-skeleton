# 0009. PostgreSQL only — no separate search or queue infrastructure

- **Status:** accepted
- **Date:** 2026-08-22

## Context

It is tempting to seed a template with Elasticsearch for search and a broker for messaging,
on the grounds that projects usually need them eventually.

## Decision

PostgreSQL 18 is the only datastore for application data. Redis is present, but only for
ephemeral concerns: rate limiting, the ACL cache and the lock store. No search cluster, no
message broker.

## Consequences

### Positive

- One thing to back up, restore and reason about for consistency. `compose.yaml` stays small
  enough to read.
- Postgres covers a great deal on its own: `citext`, JSONB, full-text search, `LISTEN/NOTIFY`,
  advisory locks.
- Nothing in the skeleton has to keep two stores in sync — the bug class that dominates
  search-index integrations.

### Negative

- Full-text search on very large corpora will eventually want a real search engine.
- Losing Redis degrades rate limiting and ACL cache performance, though correctness is
  preserved because Redis holds no source of truth.

## Alternatives rejected and why

- **Ship Elasticsearch/OpenSearch by default** — a cluster to operate, and a synchronisation
  problem to own, before any project has said it needs search.
- **Ship a broker by default** — see ADR-0010; the skeleton sends mail synchronously and does
  not need one.
- **Redis as a datastore** — durability and backup semantics we do not want for application
  data.

## Reversal cost

**Cheap to add.** Adding a search index or broker later is additive: a compose service, an
adapter in `Infrastructure`, and a port in `Application`. Nothing has to be undone.

## Implemented by

- `compose.yaml`, `compose.prod.yaml`
- `backend/config/packages/doctrine.yaml`, `backend/config/packages/cache.yaml`

-- Runs once, when the data directory is first created.

-- citext gives case-insensitive email comparison in the database rather than by lowercasing
-- in PHP. Doing it in the application means every query has to remember, and the one query
-- that forgets creates a duplicate account differing only in capitalisation.
CREATE EXTENSION IF NOT EXISTS citext;

-- Server-side UUID generation is not used — ids are generated in PHP so an entity has an
-- identity before it is flushed (ADR-0013) — but pgcrypto is useful in migrations and in
-- one-off maintenance scripts.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

# Recipe: change the database

## 1. Change the entity first

The schema is derived from the mapping, not the other way round.

## 2. Generate the migration

```bash
make sh
bin/console doctrine:migrations:diff
```

## 3. Read what it generated — always

`diff` is a starting point, not an answer. Check for:

- **A `down()` that actually reverses `up()`.** CI runs migrations **up and then down** on a
  scratch database. An empty or wrong `down()` fails the build, and rightly: a migration you
  cannot roll back is a deployment you cannot abort.
- **Accidental drops.** A rename usually appears as `DROP` + `ADD`, which silently destroys
  the data. Rewrite it as `ALTER TABLE ... RENAME COLUMN`.
- **Unindexed foreign keys**, especially anything the ACL joins on.
- **`NOT NULL` on an existing table.** Needs three steps: add nullable, backfill, then
  enforce.

## 4. Destructive changes are a two-deploy operation

Dropping a column that running code still reads causes an outage during the rollout. The
sequence is: stop reading it → deploy → drop it → deploy.

## 5. Apply

```bash
make migrate
```

## 6. Verify both directions locally

```bash
make migrate && make migrate-down && make migrate
```

If `down()` fails, fix it now. CI will catch it, but the feedback here is faster.

## Grants and the audit table

The application's database role holds INSERT only on `security_event` (ADR-0012). Migrations
run as the schema owner, not as the application user. If you add a table that the application
must write to, grant it explicitly — and if you add an audit-like table, grant INSERT only.

## Checklist

- [ ] Entity changed first, migration generated from it
- [ ] Migration read line by line, not trusted
- [ ] `down()` genuinely reverses `up()`
- [ ] Renames are renames, not drop + add
- [ ] Foreign keys and ACL join columns indexed
- [ ] `NOT NULL` added in three steps on populated tables
- [ ] `make migrate && make migrate-down && make migrate` clean
- [ ] `make check` green

# Recipe: record a decision

## When you must

A pull request touching any of these needs an ADR or the `no-adr-needed` label. The
pre-commit hook reminds you; `.github/workflows/docs.yml` enforces it.

- `backend/src/Acl/**` — the authorization model
- `backend/src/Account/**` — authentication and identity
- `backend/config/packages/security.yaml`
- `backend/deptrac.yaml`, `backend/deptrac-context.yaml` — the architecture contract
- `compose.yaml`, `compose.prod.yaml` — deployment topology

## When you should anyway

Any time you chose between real alternatives and the reason is not obvious from the diff. The
test: *would someone reading this code in a year wonder why it is like this?*

## How

```bash
make adr TITLE="Use a read replica for reporting queries"
```

The next free number and the MADR skeleton are filled in for you.

## Writing one that is worth reading

Two sections carry almost all the value:

**Alternatives rejected and why.** Not a list of what exists — the specific reason each one
lost *here*. Without this the next person re-opens the same debate and re-discovers the same
dead end. Compare:

> ~~Rejected: API Platform. We prefer plain controllers.~~
>
> Rejected: API Platform. It derives the API from entity attributes, which inverts our rule
> that the entity is not the contract, and makes "no logic in endpoints" unenforceable
> because much of the behaviour is not code in this repo.

**Reversal cost.** If this is wrong, what does undoing it cost — which files, which data
migration, which broken client contract? "Cheap", "moderate" or "expensive", plus a sentence.
This is what lets a future reader judge how hard to push back.

Also: write the **negative** consequences honestly. An ADR listing only benefits is
advocacy, and readers discount it accordingly.

## Changing an existing decision

Do not edit an accepted ADR beyond typos — it is a record of what was decided and why, at a
point in time. Write a new one that supersedes it, and set the old one's status to
`superseded by NNNN`.

## Verify

```bash
make docs      # regenerates docs/adr/README.md; commit it
```

## Checklist

- [ ] Created with `make adr` (correct number, correct template)
- [ ] Context explains the forces, not just the choice
- [ ] Both positive **and** negative consequences
- [ ] Every serious alternative, each with its specific reason for losing
- [ ] Reversal cost stated
- [ ] Links to the code that implements it
- [ ] `make docs` no diff

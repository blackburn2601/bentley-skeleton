# Reporting a vulnerability

**Please do not open a public issue.**

Report privately through GitHub's [security advisory](../../security/advisories/new) form, or
email the maintainers. Include what you found, how to reproduce it, and what an attacker could
do with it.

We will acknowledge within three working days and keep you updated until it is resolved.

## Scope

In scope: this repository and the application it builds — authentication, the ACL, the API
surface, and the container configuration.

Out of scope, because they are outside what this template can control: findings that require a
hostile database administrator, physical access, or a compromised CI runner. See
[`docs/SECURITY.md`](../docs/SECURITY.md) for the full threat model.

## What we consider a vulnerability here

The authorization model is the most sensitive part of this codebase. Anything that lets one
user reach another's data, or that lets a permission check be bypassed, is a vulnerability
even if it needs an unusual sequence of steps.

# 0010. Synchronous mail, no Messenger in the skeleton

- **Status:** superseded by 0024
- **Date:** 2026-08-22

## Context

Email verification, password reset and invitations cannot work without a mailer, so a mailer
is not optional. Asynchronous dispatch, however, is a separate decision: it brings a
transport, a worker process, supervision, retry semantics and a failure queue.

## Decision

`symfony/mailer` sending synchronously. Mailpit in development. No Messenger component, no
worker container.

## Consequences

### Positive

- One fewer process to run, supervise and debug. `make up` starts a stack that works.
- A failure to send surfaces immediately in the request, rather than silently in a queue
  nobody is watching.

### Negative

- An SMTP timeout becomes request latency on register/reset. Acceptable for these endpoints,
  which are already rate-limited and infrequent.
- Anything genuinely long-running would block a request, so it must not be added without
  introducing Messenger first.

## Alternatives rejected and why

- **Messenger with a `doctrine://` transport and a worker** — the natural next step, and the
  documented one. Rejected *for the skeleton* because it doubles the runtime topology to
  serve three low-frequency emails.
- **`sync://` Messenger transport** — Messenger's dependencies and configuration surface for
  none of its benefits; it only looks asynchronous.
- **Fire-and-forget in a kernel.terminate listener** — hides failures and still runs in the
  web process.

## Reversal cost

**Cheap.** Install `symfony/messenger`, route `SendEmailMessage` to a transport, add a worker
service to compose. No application code changes, because mail is already sent through the
mailer abstraction.

## Implemented by

- `backend/config/packages/mailer.yaml`
- `backend/src/Platform/Application/Service/` (mail sending)

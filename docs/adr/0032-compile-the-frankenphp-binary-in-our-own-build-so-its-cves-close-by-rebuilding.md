# 0032. Compile the FrankenPHP binary in our own build so its CVEs close by rebuilding

- **Status:** accepted
- **Date:** 2026-09-03
- **Deciders:** Sebastian Wagner

## Context

`.trivyignore` had six HIGH/CRITICAL entries and every one of them named the same file:
`usr/local/bin/frankenphp`. They were Go modules — `golang.org/x/crypto`,
`google.golang.org/grpc`, `github.com/getkin/kin-openapi` — compiled into the upstream binary,
where the `apt-get upgrade` that keeps our Debian packages current cannot reach them.

The nightly `security` run had been red since 2026-08-30 on two newly published ones
(CVE-2026-56854 in x/crypto, CRITICAL; CVE-2026-84304 in grpc, HIGH). The obvious answer — pin
a newer base image — does not exist: `php8.5`, `1.12.7-php8.5` and `1-php8.5` are one digest,
and its binary carried x/crypto v0.54.0 and grpc v1.81.1. Upstream `main` had already moved to
x/crypto v0.55.0 but had not shipped, and nobody upstream had touched grpc at all — Caddy
`master` was on v1.82.1, still below the fixed 1.83.1. So there was nothing to bump to, and
there would not be for an unknown number of weeks.

That left accepting them, which is what we had been doing. The trouble with the acceptance is
not that any single judgement was wrong — the x/crypto bug is an SSH server-side authorization
bypass and this image runs no SSH server — it is the **shape** of the arrangement: the list
only grows, each entry needs re-reading on expiry, and the scan's answer to "are we current?"
becomes "yes, apart from these six, for reasons written down somewhere".

A second, quieter version of the same problem was found while fixing this. The image shipped
`linux-libc-dev` 6.12.105-1 with three HIGH kernel findings whose fix, 6.12.107-1, had been in
the Debian mirror for days. `apt-get upgrade` had not missed it — the layer was never re-run.
With `cache-from: type=gha`, the security job's build served an apt layer warmed whenever it
was first built, so the step whose entire purpose is fetching fixes fetched nothing. A build
that reuses yesterday's cache cannot answer "what would we ship today".

## Decision

Compile the frankenphp binary in our own build, with its vulnerable Go modules upgraded first,
and make the layers that fetch fixes re-resolve per build rather than come from cache.

A new stage, `frankenphp-src`, starts from the upstream `builder` image — which already
carries the frankenphp source, the Go toolchain, libphp, the watcher library and the CGO flags
the link needs — runs `go get` over the advisory-carrying modules, compiles with upstream's own
ldflags, and the runtime stage copies that binary over the base image's.

Two things are deliberate about *what* floats:

- **The modules float, the server does not.** `GO_MODULE_UPGRADES` names them at `@latest`, so
  a rebuild closes tomorrow's advisory in x/crypto or grpc with nobody editing a file. The
  FrankenPHP, PHP and Caddy versions stay pinned by `FRANKENPHP_VERSION`, which now drives
  *both* frankenphp stages — our binary and the libphp it loads must come from one release, and
  the floating `php8.5` tag would have let them drift the day upstream ships 1.13.
- **A `SECURITY_REFRESH` build arg** is declared in both stages and referenced in both fetch
  layers. CI passes the date, so the nightly genuinely re-resolves `apt-get upgrade` and
  `go get @latest`; local `make up` leaves it at `0` and keeps its cache, because a dev rebuild
  should not recompile a web server.

`.trivyignore` is now empty. The production image scans clean — 0 findings on every target,
with no ignore file at all.

## Consequences

### Positive

- Nine HIGH/CRITICAL findings are **closed rather than accepted**: six Go-module ones and the
  three `linux-libc-dev` ones. `.trivyignore` went from six entries to none.
- The scan's answer to "are we current?" is now unqualified, which is the only form of that
  answer worth having.
- New advisories in the named modules close by themselves on the next build. The nightly is
  the mechanism, not a person remembering.
- We stop waiting on someone else's release cadence for our security posture. Upstream had a
  fix for one CVE unreleased and no fix at all for the other.
- Measured parity, not assumed: the self-built binary reports the same
  `FrankenPHP v1.12.7 PHP 8.5.9 Caddy v2.11.4`, exposes an **identical** Caddy module set (146
  modules, checksum-compared) and an identical PHP extension list.

### Negative

- **We own a compiled artefact now.** A Go toolchain, a link against libphp and a build that
  can fail for reasons that have nothing to do with our code. The build adds roughly 2-4
  minutes when the stage is cold; it is cached, and `SECURITY_REFRESH` deliberately leaves it
  cached locally.
- **`@latest` means two builds of one commit can differ.** This is the same trade
  `apt-get upgrade` in this Dockerfile already made, and for the same reason, but it is now
  true of compiled code as well. The mitigation is where the failure lands: a float that
  breaks compilation fails the *build*, which ships nothing, and the full suite and e2e run
  against the built image before anything is merged.
- **`FRANKENPHP_VERSION` must be bumped by hand.** We traded automatic FrankenPHP upgrades (the
  floating tag) for a pinned pair, because a binary/libphp skew fails as a segfault under load
  rather than a build error. Bumping is one line and a verification run.
- A new module carrying an advisory needs adding to `GO_MODULE_UPGRADES` — a one-line change
  Trivy names for us, but still a human step.
- The build needs network access to the Go module proxy, which the previous build did not.

## Alternatives rejected and why

- **Keep accepting them with expiry dates.** What we were doing. It is defensible per entry and
  wrong in aggregate: the list only grows, and the expiry mechanism re-asks a question that
  nothing upstream had answered. Rejected once it was clear a rebuild closes all six.

- **Wait for an upstream release.** Would have closed exactly one of the two new CVEs, on an
  unknown date, and nothing at all for grpc — Caddy `master` itself was still below the fixed
  version. Waiting was not a plan, it was a hope.

- **Build a slimmer Caddy without the OpenTelemetry OTLP-gRPC exporters**, so grpc is not in
  the binary at all. Strictly the strongest fix: absent code cannot be vulnerable. Rejected as
  the wrong trade *here* — it means owning an explicit Caddy module list and discovering by
  outage that something needed a module we dropped, whereas upgrading the module keeps the
  binary's capabilities provably identical (that is what the 146-module comparison is for).
  Worth revisiting if grpc becomes a recurring source of findings.

- **`go get -u ./...`, upgrading everything.** More automatic, and it would have caught modules
  not on our list. Rejected on the stability grounds that drove this whole change: floating
  every transitive dependency of a web server on every nightly is a large surface for a small
  gain over naming the three that actually produce advisories.

- **A VEX statement instead of an ignore file.** Better provenance than `.trivyignore` — a
  standard, machine-readable "not affected, vulnerable code not in execute path". Rejected
  because it is still an acceptance, and it argues about reachability where a rebuild removes
  the question. Reconsider for a future CVE that genuinely cannot be rebuilt away.

- **Pin the two modules to exact fixed versions** (`@v0.55.0`, `@v1.83.1`) rather than
  `@latest`. Reproducible, and it was the first thing verified as working. Rejected against the
  stated goal: it closes today's CVE and leaves tomorrow's to whoever notices the nightly went
  red. The point of this ADR is that the build stays current on its own.

- **`no-cache: true` on the security job's build.** Fixes the stale-apt half and nothing else,
  and it makes every PR pay a full rebuild. `SECURITY_REFRESH` targets the two layers that need
  to re-resolve and leaves the rest of the cache intact.

## Verification

Stability was the explicit precondition for this change, not an afterthought, so it was
verified against a candidate image before the Dockerfile was touched:

- `frankenphp version` → `FrankenPHP v1.12.7 PHP 8.5.9 Caddy v2.11.4`, unchanged.
- Caddy module set: identical to the released binary, 146 modules, same checksum.
- PHP version and extension list: identical.
- Production image boots and `/health/ready` reports `cache`, `database` and `migrations` all
  `up`.
- Full backend suite on the self-built binary: **407 tests, 1866 assertions, OK**.
- Playwright e2e against a stack running it: **24 passed**.
- Trivy on the production target with **no ignore file**: 0 findings on debian, gobinary,
  composer-vendor and node-pkg.

The floated set resolved to x/crypto v0.56.0, grpc v1.83.2 and kin-openapi v0.149.0 — all
ahead of the versions the advisories require — and compiled and passed the above unchanged.

## Reversal cost

Cheap, and separable. Dropping the self-built binary is deleting the `frankenphp-src` stage and
one `COPY`; the base image's own binary is then used again, and the six accepted entries would
have to come back to `.trivyignore`. Dropping only the freshness mechanism is removing the two
`SECURITY_REFRESH` references and the date step in `security.yml`. Pinning the modules instead
of floating them is editing one `ARG`. No schema change, no API change, and nothing about the
application code depends on any of it.

## Implemented by

- `docker/frankenphp/Dockerfile` — the `frankenphp-src` stage, the shared
  `FRANKENPHP_VERSION`, and `SECURITY_REFRESH` on both fetch layers.
- `.github/workflows/security.yml` — the date stamp passed into the image build, and the
  `pull-requests: read` grant that the gitleaks job needed on pull_request events.
- `compose.yaml` — `SECURITY_REFRESH` defaulted to `0` so local rebuilds stay cached.
- `.trivyignore` — now empty, with the history of why kept in its header.

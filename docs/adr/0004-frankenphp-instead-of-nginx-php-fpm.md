# 0004. FrankenPHP instead of nginx + php-fpm

- **Status:** accepted
- **Date:** 2026-08-22

## Context

The stack needs a container that serves the API in development and in production. The
conventional answer is two processes — nginx for HTTP and static files, php-fpm for PHP —
usually as two containers with a shared volume.

## Decision

A single FrankenPHP container (Caddy + the PHP runtime), same image in development and
production, with the built SPA served from `public/`.

## Consequences

### Positive

- One process, one image, one set of logs. Development and production differ in
  configuration, not in topology, so "works locally" means more.
- Automatic HTTPS and HTTP/2 / HTTP/3 from Caddy without extra configuration.
- Worker mode is available when throughput matters, without changing the deployment shape.

### Negative

- Smaller community than nginx + php-fpm; fewer answers when something is unusual.
- Worker mode changes process lifetime, so state that leaks between requests becomes a bug.
  INV-09 (`final readonly` services, no mutable state) is what makes that safe here.
- Caddy configuration is unfamiliar to people who know nginx.

## Alternatives rejected and why

- **nginx + php-fpm** — well understood, but two containers, two configs, two log streams
  and a shared volume, all to serve one application.
- **Apache + mod_php** — simpler than nginx+fpm, worse at static file serving and
  concurrency, and no HTTP/3.
- **RoadRunner** — comparable worker-mode benefits, but adds a Go supervisor and its own
  configuration model without removing the need for a web server.

## Reversal cost

**Cheap.** Swapping in nginx + php-fpm means replacing `docker/frankenphp/` and two compose
services. No application code refers to the runtime.

## Implemented by

- `docker/frankenphp/Dockerfile`, `docker/frankenphp/Caddyfile`
- `compose.yaml`, `compose.prod.yaml`

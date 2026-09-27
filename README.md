# booking-engine

[![build-scan-sign](https://github.com/monospaced-dev/booking-engine/actions/workflows/build-sign.yml/badge.svg)](https://github.com/monospaced-dev/booking-engine/actions/workflows/build-sign.yml)

A small, schema-agnostic booking API built with Laravel 13 and PostgreSQL. It
answers one question for other services: **when is this resource busy?**, and
it guarantees that two bookings for the same resource can never overlap.

It was built as the scheduling backend for Inspector Service (a home-inspection
app), but it knows nothing about inspections. Callers register their own
things as *resources* (`external_type` + `external_id`), book time against
them, and ask for merged busy ranges. Turning busy time into free slots,
business hours or anything else domain-specific stays in the caller.

## Design highlights

- **Overlaps are impossible at the database level.** Each booking stores its
  time as a `tstzrange`, and a GiST exclusion constraint
  (`EXCLUDE USING gist (resource_id WITH =, during WITH &&)`, via
  `btree_gist`) rejects any overlapping booking for the same resource. There
  is no check-then-insert race; a conflict surfaces as `409 Conflict`.
- **Idempotent booking creation.** `POST .../bookings` accepts an
  `Idempotency-Key` header, backed by a partial unique index on
  `(resource_id, idempotency_key)`. A retry returns the original booking with
  `200`, including when two identical requests race each other.
- **Busy-time oracle.** `GET .../busy` merges bookings and blackout dates into
  one sorted list of non-overlapping ranges, resolved in the resource's own
  timezone (so full-day blackouts mean the resource's midnight, not UTC's).
- **API-key auth, stored hashed.** Keys are 64-char random strings; only their
  SHA-256 hash is stored. Keys can be deactivated and reactivated without
  deleting them.
- **Honest health check.** `GET /api/health` actually opens a database
  connection and returns `503` if it can't, without leaking connection details.

## API

All routes are under `/api`. Everything except `health` and `ping` requires an
`X-Api-Key` header.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/health` | DB connectivity check (no auth) |
| `GET` | `/ping` | Liveness, `204` (no auth) |
| `POST` | `/resources` | Register a resource (`external_type`, `external_id`, `timezone`); idempotent on the external pair |
| `PUT` | `/resources/{resource}` | Change a resource's `timezone` |
| `GET` | `/resources/{resource}/busy?from=&to=` | Merged busy ranges in a window |
| `GET` | `/resources/{resource}/bookings?from=&to=` | List bookings, optionally in a window |
| `POST` | `/resources/{resource}/bookings` | Create a booking (`starts_at`, `ends_at`, optional `metadata`); `409` on overlap |
| `DELETE` | `/resources/{resource}/bookings/{booking}` | Cancel a booking |
| `GET` | `/resources/{resource}/blackout-dates` | List blackout dates |
| `POST` | `/resources/{resource}/blackout-dates` | Add a blackout (`date`, optional `start_time`/`end_time` as `H:i`, optional `note`); no times = full day |
| `DELETE` | `/resources/{resource}/blackout-dates/{blackoutDate}` | Remove a blackout |

Example:

```bash
curl -s -X POST http://localhost:8080/api/resources/1/bookings \
  -H "X-Api-Key: $API_KEY" \
  -H "Idempotency-Key: appointment-42" \
  -H "Content-Type: application/json" \
  -d '{"starts_at":"2026-10-01T14:00:00-05:00","ends_at":"2026-10-01T16:00:00-05:00"}'
```

### API keys

```bash
php artisan apikey:generate --name=inspector-service    # prints the key once
php artisan apikey:deactivate --name=inspector-service
php artisan apikey:reactivate --name=inspector-service
```

## Running locally

With Docker (Postgres, php-fpm and nginx, same images as production):

```bash
docker compose up --build
docker compose exec fpm php artisan migrate
docker compose exec fpm php artisan apikey:generate --name=local
curl http://localhost:8080/api/health
```

Postgres is published on host port `5433` so it doesn't clash with a local
install. The compose file's credentials and `APP_KEY` are for local use only.

### Tests

The suite uses Pest and runs against PostgreSQL. The exclusion constraint and
`tstzrange` columns don't exist in SQLite. `.env.testing` expects a
`booking_engine_test` database on `127.0.0.1:5432`.

```bash
composer install
composer test
```

## Container images and supply chain

Production runs as two containers in one pod: **php-fpm** (`docker/Dockerfile.fpm`)
and **nginx** (`docker/Dockerfile.nginx`), talking over the pod's loopback.
Both are built for `linux/arm64` and run as non-root numeric users (`33` and
`101`), so they pass the Kubernetes *restricted* Pod Security profile.

[`build-scan-sign`](.github/workflows/build-sign.yml) runs on every push to
`main`:

1. Builds each image natively on an arm64 runner.
2. Scans it with **Trivy** and fails on any fixable HIGH or CRITICAL
   vulnerability, before anything is published.
3. Pushes to GHCR: `ghcr.io/monospaced-dev/booking-engine-fpm` and
   `ghcr.io/monospaced-dev/booking-engine-nginx`.
4. Signs each image by digest with **cosign** keyless signing (GitHub OIDC,
   recorded in Sigstore's Rekor transparency log).

Pull requests are built and scanned but never pushed or signed. Every
third-party action is pinned to a full commit SHA, not a tag.

Verify an image yourself:

```bash
cosign verify ghcr.io/monospaced-dev/booking-engine-fpm@sha256:<digest> \
  --certificate-identity-regexp '^https://github.com/monospaced-dev/booking-engine/\.github/workflows/build-sign\.yml@refs/heads/main$' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

## Deployment

It runs on a self-hosted arm64 k3s cluster, managed by Argo CD from
[homelab-platform](https://github.com/monospaced-dev/homelab-platform).
Images are deployed by digest. Kyverno admits only images signed by this
workflow, and the namespace is locked down with default-deny NetworkPolicies.

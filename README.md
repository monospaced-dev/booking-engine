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
- **Busy-time oracle.** `GET .../busy` merges booking and blackout instant ranges
  into one sorted list of non-overlapping ranges in UTC. Consumers calculate
  local-day and DST boundaries and send explicit offsets; resources have no timezone.
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
| `POST` | `/resources` | Register a resource (`external_type`, `external_id`); idempotent on the external pair |
| `GET` | `/resources/{resource}/busy?from=&to=` | Merged busy ranges in a window |
| `GET` | `/resources/{resource}/bookings?from=&to=` | List bookings, optionally in a window |
| `POST` | `/resources/{resource}/bookings` | Create a booking (`starts_at`, `ends_at`, optional `metadata`); `409` on overlap |
| `DELETE` | `/resources/{resource}/bookings/{booking}` | Cancel a booking |
| `GET` | `/resources/{resource}/blackouts` | List blackouts, optionally filtered by paired `from`/`to` instants |
| `POST` | `/resources/{resource}/blackouts` | Add a blackout (`starts_at`, `ends_at` with explicit offsets, optional `note` up to 255 characters); at most 366 days |
| `DELETE` | `/resources/{resource}/blackouts/{blackout}` | Remove a blackout |

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

## Operability

Authenticated `/api` routes share a per-API-key budget across routes: by default
600 requests per minute, set by `API_RATE_LIMIT_PER_MINUTE`. Excess requests return
429 `too_many_requests` with `Retry-After` in seconds. `/api/health` and `/api/ping`
are exempt. Key usage timestamps update only when null or strictly older than
60 seconds, via one conditional UPDATE.

The configured cache store is used for throttling. `config/cache.php` defaults to
`database`, `.env.example` specifies `CACHE_STORE=database`, and the production-mode
Compose service does not override it. Limits are shared across pods using the
same database/cache table and cache prefix. External production environment
configuration is outside this repository; verify it has no `CACHE_STORE` override.
A `file` or `array` override makes limits local to the pod or process respectively.

Prometheus should scrape **HTTP port 8080, path `/metrics`** on each nginx/FPM pod,
with `Authorization: Bearer <METRICS_TOKEN>`. Port 9000 speaks FastCGI, not HTTP.
Set `METRICS_TOKEN` to a nonempty secret; its default is unset (endpoint returns
404). Missing or wrong bearer credentials return 401; API keys do not grant access.
The token comparison uses `hash_equals`. No Kubernetes manifests are changed.

`promphp/prometheus_client_php` provides Prometheus text format:

- `booking_engine_http_requests_total`: request counter.
- `booking_engine_http_request_duration_seconds`: histogram in seconds, using the
  client's default buckets.

Both use `method`, `route` and `status` labels. Routes include their URI template
(e.g. `/api/resources/{resource}/busy`); unknown routes use `unmatched`. Final
rendered errors are counted; `/metrics` requests are excluded regardless of status.
Latency runs from entry into global middleware through rendering the response,
excluding network transmission. APCu storage in the FPM image shares metrics among
workers in one pod; counters reset on restart or APCu eviction and are **not shared
across pods**. Scrape every pod and aggregate in Prometheus. Tests use isolated
in-memory storage. `/metrics` is operational and excluded from the API OpenAPI spec.

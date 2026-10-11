# Phase 3 consumer contract changes

Implemented on `fix/phase-3-contract`; contract source: [openapi.yaml](openapi.yaml).
Update BookingEngineClient before bringing the offline consumer back online.
Timestamp replacements and status changes below are intentional.

| Endpoint | Before | After |
|---|---|---|
| All API error responses | No machine-readable code; most had only `message` | Always `message` and `code`. Existing fields remain, including validation `errors` and framework debug fields. |
| Protected operations, 401 | `message: Unauthorized` | Same message, plus `code: unauthorized` |
| POST `/api/resources/{resource}/bookings`, 409 | Only `error` | Retains `error`; adds identical `message` and `code: booking_conflict` |
| All validated operations, 422 | Laravel `message` and field `errors` | Same fields, plus `code: validation_failed` |
| DELETE `/api/resources/{resource}/bookings/{booking}` and `/blackout-dates/{blackoutDate}`, wrong parent | 403, `message: Forbidden` | 404, `message: Resource not found.`, `code: not_found`; record retained |
| Resource-bound operations, missing resource or child | 404, `message: Resource not found.` | Same status/message, plus `code: not_found` |
| Unknown `/api/*` route | 404, `message: Resource not found.` | 404, `message: Route not found.`, `code: route_not_found` |
| Framework API errors | Framework payload without code | Retains payload, adds message when absent and status-based code: `unauthorized` (401), `forbidden` (403), `method_not_allowed` (405), `validation_failed` (422), `too_many_requests` (429), `internal_error` (other 5xx), `request_failed` (other errors) |
| GET `/api/health`, 503 | `status: error`, `database: unreachable` | Retains both; adds `message: Database unavailable.`, `code: database_unavailable` |
| POST `/api/resources/{resource}/bookings`, 201 | No `created_at` or `updated_at` | Adds both stored timestamps; same full fields and values as GET and 200 replay |
| POST and GET `/api/resources/{resource}/bookings` (including 200 replay) | `starts_at`/`ends_at`, and existing audit timestamps, used fractional `Z` format, e.g. `2026-07-21T15:00:00.000000Z` | All four timestamps use UTC seconds, e.g. `2026-07-21T15:00:00+00:00` |
| POST and GET `/api/resources/{resource}/blackout-dates` | `created_at`/`updated_at` used fractional `Z` format | Both use UTC seconds with `+00:00` |
| POST `/api/resources/{resource}/blackout-dates`, 201 | Optional keys absent if omitted; supplied times returned as `HH:MM` | Always includes `start_time`, `end_time`, `note` (null if absent). Times use stored `HH:MM:SS`; full object matches GET |
| POST `/api/resources` | 200 for both creation and lookup | 201 on creation, 200 for an existing resource; retains the full existing payload and `created` boolean |

`/busy` already uses the agreed timestamp format. No changes to datetime input
validation from Phase 2, metadata storage/shape, idempotency behavior, timezone
conflicts, interval storage, deletion success (204), or resource update fields.
The OpenAPI document covers the service routes, including health and ping;
the unused `/api/user` scaffold and web `/up` route remain outside the spec.

Validation: all behavior changes first reproduced by failing tests, then fixed.
The validator's Composer dry run resolved 11 new dev dependencies with no
updates or removals of existing packages. Real HTTP response schema checks and
negative mutation checks run in the existing Postgres-backed Pest suite.


## Phase 4: idempotency hardening

Implemented on `fix/phase-4-idempotency` (B5, B6, B7). All rows below apply to
POST `/api/resources/{resource}/bookings`; the header remains optional.

| Endpoint | Before | After |
|---|---|---|
| POST `/api/resources/{resource}/bookings`, invalid `Idempotency-Key` | Arbitrary keys accepted; oversized values could return 500; empty and `0` skipped replay lookup | A supplied header must match `^[A-Za-z0-9_-]{1,128}$`. Invalid values return 422 `validation_failed` with `errors.Idempotency-Key`. `0` replays correctly. |
| POST `/api/resources/{resource}/bookings`, reused key with changed payload | 200 with the original booking, silently ignoring changes | 422 `idempotency_mismatch` with `message` and `code`; original booking retained. |
| POST `/api/resources/{resource}/bookings`, matching concurrent retry | Exclusion violation could return a spurious 409 | Re-select by `(resource_id, idempotency_key)` after 23P01 or 23505; matching payload returns 200 with the same full stored booking as other replays. |
| POST `/api/resources/{resource}/bookings`, different concurrent payload | 409 on exclusion violation, or unchecked replay on unique violation | A found key with differing payload returns 422 `idempotency_mismatch` through the shared replay path. An exclusion violation with no row for the requested key remains 409 `booking_conflict`. |

Comparison uses UTC instants at the existing INSERT's second precision and the
existing validated metadata (only `appointment_id` is retained). Equivalent
explicit offsets match. Omitted metadata and an empty array match the existing
empty stored payload. Unknown metadata fields retain their existing behavior;
this phase does not implement B8. Keys remain resource-scoped. No fingerprint,
migration, endpoint or booking storage change was added. The OpenAPI header
constraint and both booking 422 envelopes are documented and checked against
real responses.

Concurrency coverage lives outside `RefreshDatabase` in `tests/Integration`.
Each case runs with `DatabaseMigrations` and starts a separate PHP process with
its own Postgres connection. The winning INSERT stays uncommitted until
`pg_blocking_pids` proves the HTTP retry is blocked on it, then commits. A thin
connection subclass records and rethrows real query exceptions; no exception is
mocked. Cases cover matching replay, changed start/end/metadata, missing key,
unkeyed winner and a disjoint-range 23505 mismatch. Processes, connections and
committed fixtures are cleaned up in `finally`; migrations reset each case.

Validation: full suite passed on PostgreSQL 17.11 with `--fail-on-risky`: 149
tests, 672 assertions. B7, B5 and B6 each had failing regression tests confirmed
before implementation and are committed separately. Pint and diff checks pass.


## Phase 5: atomic reschedule and booking versions

Implemented on `feat/phase-5-reschedule`; contract source: [openapi.yaml](openapi.yaml).

| Endpoint / header | Consumer contract |
|---|---|
| GET `/api/resources/{resource}/bookings/{booking}` | 200 full booking, same object shape as list; quoted `ETag` matches `lock_version`. Wrong parent or missing resource/booking: 404 `not_found`. |
| PATCH `/api/resources/{resource}/bookings/{booking}` | Requires `starts_at` and `ends_at`, using POST validation: explicit offsets, end after start. Changes times only; resource and metadata input are ignored. Success: 200 full booking with incremented version and matching quoted `ETag`. |
| Booking objects in POST, GET one, PATCH and list | Add integer `lock_version`, initially 1. POST creation and replay, GET one and PATCH include strong quoted `ETag`, e.g. `"1"`. List has versions on objects and no booking ETag header. |
| PATCH `If-Match` | Send exactly one strong quoted version from the latest booking ETag, e.g. `"1"`. Missing: 428 `precondition_required`. Stale, malformed, weak, wildcard or list: 412 `precondition_failed`. |
| PATCH overlap | 409 `booking_conflict`, same `error`/`message`/`code` envelope as POST. Previous times, updated_at and lock_version remain unchanged. Self-overlap and adjacent half-open ranges are allowed. |
| PATCH concurrency | Version comparison, range update, database-clock updated_at and version increment happen in one UPDATE scoped by booking and resource. A stale version wins over an overlapping target (412). Two concurrent requests carrying the same version produce one 200 and one 412. Every success, even a no-op, increments the version. |
| PATCH `Idempotency-Key` | Ignored, including invalid values. The stored key remains untouched. After changing times, retrying POST with the original key and original times intentionally returns 422 `idempotency_mismatch`. |

On 412, GET the latest booking and make an explicit decision before sending a new
PATCH with its current ETag. PATCH does not enforce blackouts or move a booking
between resources. Wrong-parent and missing-row checks return 404 before PATCH
validation/preconditions. With an existing booking, missing If-Match takes
precedence over input validation; time validation runs before matching a supplied
tag. Successful responses preserve all non-time fields except the incremented
version and database-clock updated_at. All datetimes retain UTC-second precision.

The migration only adds `lock_version integer NOT NULL DEFAULT 1`; it is safe to
apply while the prior application version runs, including inserts that omit the
column. Deploy the migration before the new application. The overlap refactor
was committed independently with no behavior change (`a2506c3`); the full suite
passed before endpoint work began. No holds, blackout changes, soft-cancel, or
B8-B13 fixes are included.

Validation: endpoint regression tests failed before implementation. The existing
two-process worker now also drives an actual HTTP PATCH, holds its transaction,
and commits only after `pg_blocking_pids` proves the competing request is blocked.
Coverage proves conflict rollback, self-overlap, adjacency, no-op increments,
precondition precedence, retained keys/metadata and database-clock timestamps.
OpenAPI validation covers real endpoint responses, ETags, new error envelopes,
and the required If-Match request header, with negative mutation checks.

Full suite: **186 tests passed, 849 assertions** on PostgreSQL **17.11**, using
`--fail-on-risky`. Pint and `git diff --check` pass.

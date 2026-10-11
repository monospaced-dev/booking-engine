# Consumer contract changes

## Phase 3

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


## Phase 6: resource, metadata and range hardening

Implemented on `fix/phase-6-hardening` (ranked #12: B8, B9, B11 and range
constraints). Contract source: [openapi.yaml](openapi.yaml).

| Endpoint | Before | After |
|---|---|---|
| All resource, booking and blackoutDate path parameters | Non-numeric or out-of-range IDs could cause database errors / 500 | Must match `[0-9]{1,18}`; anything else returns 404 `route_not_found`. Valid but missing IDs return 404 `not_found`. |
| POST `/api/resources/{resource}/bookings`, metadata | Only appointment_id retained; unknown keys silently dropped | Flat opaque object, at most 20 keys matching `^[A-Za-z0-9_]{1,40}$`; values are strings of at most 500 characters or strict integers. All valid keys and values retained, including empty strings and whitespace. appointment_id must be an integer. Violations return 422 `validation_failed`. |
| POST bookings, empty metadata | Empty array / omitted metadata accepted and returned as `[]` | Omitted, `{}` and `[]` are equivalent. Non-empty lists are rejected. All booking responses (POST/replay, list, GET one, PATCH) serialize empty metadata as `{}`, including legacy rows. No stored-row rewrite. |
| POST bookings, idempotency replay | Metadata array comparison depended on key order | Keys sorted on both sides before strict comparison; reordered keys replay with 200. Integer `7` and string `"7"` differ and return 422 `idempotency_mismatch`. |
| POST `/api/resources`, existing identity with different timezone | 200 with the stored timezone, silently ignoring the supplied timezone | 409 `timezone_mismatch`, with message/code; resource unchanged. Same timezone still returns 200. Unique-violation races re-select the winner and apply identical rules. |
| POST bookings and PATCH `/api/resources/{resource}/bookings/{booking}` | No duration sanity bound | At most 366 elapsed days (366 × 86400 seconds); longer ranges return 422 `validation_failed` on ends_at without a write. This is a sanity limit, not a consumer business rule. |
| `/api/user` | Unused Sanctum scaffold endpoint | Removed; returns 404 `route_not_found`. |

Metadata is opaque client reference data: stored and returned unchanged, never
interpreted, indexed or logged by the engine. Do not use it for personal
information. JSON object key order is not significant. No nested objects, lists,
booleans, floats or nulls are accepted as values.

PUT resource behavior is unchanged. Changing a timezone shifts existing
blackouts; that hazard is removed by the later blackout-range work.

Apply the additive migration before deploying the application. The CHECK rejects
empty ranges, unbounded ranges, and explicit infinite timestamp bounds. It
validates existing rows and fails loudly if any violate it, with no data rewrite.
The ALTER TABLE takes a table lock for validation; the previous application may
continue to use its existing finite, nonempty INSERT/UPDATE statements. Duration
is enforced only on new POST/PATCH requests; finite legacy ranges longer than
366 days remain valid. Down removes only the new CHECK.

Each behavior change was reproduced by failing tests before implementation.
Two-process resource creation tests synchronize with pg_blocking_pids and observe
real 23505 violations, proving matching lookup and timezone mismatch recovery.
Database tests cover empty, unbounded and explicit infinite ranges, validation of
bad legacy rows, and previous-writer compatibility. The cleanup commit removes
the unused InvalidAvailabilityException and /api/user route; both scaffold
ExampleTest files were already absent from main.

Validation: **231 tests passed, 998 assertions** on PostgreSQL **17.11**, with
`--fail-on-risky`. OpenAPI real-response validation, Pint and diff checks pass.


## Phase 7: blackout ranges and resource timezone removal

Implemented on `feat/phase-7-blackout-ranges` (ranked #7, first half of F3,
B11 supersession, and the Phase 6 route-ID carry-over fix). Contract source:
[openapi.yaml](openapi.yaml).

The only consumer is offline until further notice. Breaking contract changes
are intentional, and migrations do not need to remain compatible with the
previous application version. Update the consumer before bringing it online.

| Endpoint | Before | After |
|---|---|---|
| All ID paths | `[0-9]{1,18}` allowed zero and leading zeros; OpenAPI IDs were strings | `[1-9][0-9]{0,17}`; invalid IDs return 404 `route_not_found`. OpenAPI integer paths: minimum 1, maximum 999999999999999999. Valid absent IDs return 404 `not_found`. |
| GET/POST/DELETE `/api/resources/{resource}/blackout-dates[/{blackoutDate}]` | Resource-local date/time rows | Removed entirely; 404 `route_not_found`. No compatibility period. |
| POST `/api/resources/{resource}/blackouts` | No endpoint | Required starts_at/ends_at with explicit offsets, end after start, at most 366 elapsed days; optional nullable string note, max 255. 201 full stored object. Overnight, multi-day and overlapping ranges allowed. |
| GET `/api/resources/{resource}/blackouts` | No endpoint | All resource rows, or half-open overlap with paired optional from/to bounds using booking-list validation. Full unclipped objects; no ordering guarantee. Empty list is `[]`. |
| DELETE `/api/resources/{resource}/blackouts/{blackout}` | No endpoint | 204; missing row or wrong parent returns 404 `not_found`. |
| GET `/api/resources/{resource}/busy` | Converts local blackout dates/times using resource timezone | Reads booking/blackout ranges using the shared overlap scope, merges and clips them. Response shape and UTC format unchanged. |
| POST `/api/resources` | Required/returned timezone; differing timezone produced 409 `timezone_mismatch` | Accepts external_type/external_id only; 201 on creation, 200 on existing identity, including concurrent creation races. Returns id, external_type, external_id, created. Timezone input is ignored like other unknown fields. |
| PUT `/api/resources/{resource}` | Updates resource timezone | Removed; 404 `route_not_found`. |
| POST/PATCH bookings | Does not enforce blackouts | Unchanged: bookings may still overlap blackouts. |

Blackout objects contain exactly `id`, `resource_id`, `starts_at`, `ends_at`,
`note`, `created_at`, `updated_at`. All timestamps use standard UTC seconds with
`+00:00`. Blackouts have no PATCH, idempotency key, ETag or lock_version. Repeated
POSTs create independent rows, even with an Idempotency-Key header.

The consumer owns local-midnight and DST calculations. For a Chicago full-day
blackout on 2026-03-08, send `2026-03-08T00:00:00-06:00` through
`2026-03-09T00:00:00-05:00` (23 elapsed hours); on 2026-11-01, send
`2026-11-01T00:00:00-05:00` through `2026-11-02T00:00:00-06:00` (25 hours).

Migration order is create blackouts, SQL backfill using the old resource
timezone, drop blackout_dates, then drop resources.timezone. Full-day boundaries
are converted independently from local midnight to next local midnight; partial
rows use their local times. If either legacy time is null, the row remains a
full day, matching the previous busy behavior. Note and timestamps are retained;
blackout IDs are newly assigned. The composite GiST index has no exclusion
constraint; the finite/nonempty CHECK matches bookings. Rollbacks restore
structure only and do not recover the original local-date data or timezones.
Legacy notes over 255 characters fail the migration rather than being truncated.
Stop the old application before migrating and deploy the new application after.

No runtime resource-timezone use remains. Historical migrations, the backfill,
structure rollback and legacy migration fixtures necessarily retain timezone
references. Historical phase notes describe their original contracts; this
section supersedes their timezone and blackout-date contracts. Recurring
blackouts, bulk writes, external calendars and B13 are outside scope.

Validation: regression tests failed before each behavior implementation.
Migration tests assert exact Chicago/Tokyo partial-day instants, normal full-day
instants, both Chicago DST days, preserved fields, and the final removal order.
Database tests cover finite/nonempty ranges, overlaps, the GiST index and cascade
delete. Busy tests preserve the Phase 2 boundary cases and cover merging,
adjacency, clipping and isolation. Two-process creation coverage observes an
actual blocked INSERT and SQLSTATE 23505 before re-selecting the resource.
OpenAPI real-response and request validation accept integer ID paths.

Full suite: **258 tests passed, 1,105 assertions** on PostgreSQL **17.11**,
using `--fail-on-risky`. Pint and `git diff --check` pass.


## Phase 8: operability and small leftovers

Implemented on `feat/phase-8-operability`. No migrations or new infrastructure.

| Endpoint | Before | After |
|---|---|---|
| Authenticated `/api/*` routes | Unlimited requests | One budget per API key across routes; default 600/minute. Excess returns 429 `too_many_requests` plus `Retry-After` seconds. Invalid/inactive keys still return 401. |
| `/api/health`, `/api/ping` | Unauthenticated probes | Still unauthenticated and exempt from throttling. |
| Authenticated API-key usage | Writes `last_used_at` on every request | One conditional UPDATE changes it only when null or strictly more than 60 seconds old; freshness checked in SQL. |
| GET `/api/resources/{resource}/bookings` and `/blackouts` | No ordering guarantee | Range start ascending, then id ascending, with or without an overlap window. Full unclipped ranges and response shapes unchanged. |
| DELETE `/api/resources/{resource}/bookings/{booking}` | Unconditional delete; If-Match ignored | Optional one strong quoted lock_version (e.g. `"1"`), matching the current version in the same DELETE. Stale/malformed tags return 412 `precondition_failed` without deleting. Absent header preserves unconditional 204. Missing/wrong-parent rows return 404. |
| GET `/metrics` | No operational endpoint | Prometheus text format; independent bearer token, no API key. Unset/empty configured token returns 404; missing/wrong credentials return 401. |
| GET `/` | Unused welcome page | 404; welcome view removed. |

New environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `API_RATE_LIMIT_PER_MINUTE` | `600` | Positive request budget per API key per minute, shared across authenticated routes. |
| `METRICS_STORAGE` | `apcu` | Adapter: `apcu` or `memory`; APCu unavailable/disabled falls back to memory. Tests explicitly use memory. |
| `METRICS_TOKEN` | Unset/empty | Independent scrape secret; leave empty to disable `/metrics` (404). |

The application uses its configured cache store for throttling. The production
repository default is `database` (`config/cache.php`, `.env.example`, and no
Compose override). Counts are shared across pods using the same database/cache
table and prefix. External production environment overrides are not visible in
this checkout; `file`/`array` overrides make counts local instead.

Metrics use `promphp/prometheus_client_php` 2.15.1 with APCu in production (extension
added to the FPM image), selected through `METRICS_STORAGE` (default `apcu`).
Tests explicitly select `memory`. Missing or disabled APCu falls back to memory;
this fallback does not persist across separate FPM requests. The request counter
`booking_engine_http_requests_total` and histogram
`booking_engine_http_request_duration_seconds` have `method`, `route`, `status`
labels. `route` is the URI template with its leading slash, or `unmatched`; raw
paths/query strings never become labels. Final rendered failures are counted.
Recording failures (including registry construction) are caught and logged at
warning level without changing the API response. Scrape requests themselves are excluded. APCu metrics are shared by FPM workers
in one pod, reset on restart/eviction, and are not shared between pods.

Scrape **HTTP port 8080, path `/metrics`** on each pod with
`Authorization: Bearer <METRICS_TOKEN>`. Port 9000 is FastCGI. Operational metrics
are deliberately excluded from OpenAPI; deployment configuration belongs in the
README. This phase changes no Kubernetes manifests.

Regression tests reproduced missing throttling, excessive usage writes, missing
metrics access/collection, unordered lists, ignored DELETE versions, and the
welcome page before implementing those behaviors. A real two-process PostgreSQL
race verifies DELETE rechecks If-Match after a competing PATCH commits. OpenAPI
real-response tests cover 429 and 412; the optional DELETE request header is
specified, alongside deterministic list ordering. The full PostgreSQL 17 suite
passes with both the default test array cache and production-default database cache, using `--fail-on-risky`: **291 tests, 1,263 assertions**. The production FPM
image builds successfully with APCu; separate FastCGI requests verify counters and
histograms persist across requests and bearer-protected scrapes are excluded.

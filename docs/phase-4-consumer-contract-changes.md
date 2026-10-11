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

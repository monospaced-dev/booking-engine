# booking-engine review

Reviewed 2026-10-10 at commit `f62fba0`. No application code was modified; this file is the only change.

**How this was produced.** I read every file under `app/`, `routes/`, `database/migrations/`, `tests/`, both CI workflows, and the Docker setup. I ran the test suite. I also wrote throwaway probe scripts (kept outside the repo) that drove the real HTTP kernel against Postgres 16 inside rolled-back transactions. Anything marked **verified** was reproduced that way. Anything marked **unverified** is reasoning from the code.

**What I cannot see:** the consumer (`BookingEngineClient` in Inspector Service) and the production environment config, which presumably lives in `homelab-gitops` or `homelab-platform`. Several findings depend on exactly what the client sends, and I say so where it matters.

---

## 1. Architecture summary

A Laravel API over Postgres that answers one question: *when is resource X busy?* It does not compute free slots or hold availability rules.

- **Auth:** `X-API-Key` header, SHA-256 hashed lookup in `api_keys` (`AuthenticateApiKey.php`). Keys are created by artisan commands. `/api/health` and `/api/ping` are unauthenticated by design.
- **Resources:** `resources(external_type, external_id, timezone)`, upserted by the consumer.
- **Bookings:** `bookings.during` is a `tstzrange`. A GiST exclusion constraint `(resource_id =, during &&)` makes overlap impossible at the database level. `CreateBooking` is a raw `INSERT … ?::tstzrange` that maps SQLSTATE `23P01` to a 409. An `Idempotency-Key` header is backed by a partial unique index.
- **Blackouts:** `blackout_dates(date, start_time?, end_time?)`, stored as resource-local wall-clock values and converted to UTC at read time.
- **Busy query:** `GetBusyRanges` fetches overlapping bookings plus blackouts, converts both to UTC instants, and `Interval::mergeExclusions` clips and merges them.
- **Delivery:** php-fpm + nginx images. A GitHub workflow builds, Trivy-scans and cosign-signs them. A Gitea workflow builds and bumps `homelab-gitops`.

**Test run:** 51 tests, 51 passed, 104 assertions, 0.8s, on Postgres 16 (a throwaway container I started). Without a database, the same command gives **14 passed / 37 errors**, because the suite needs `booking_engine_test` on `127.0.0.1:5432` and nothing starts it. **Neither CI workflow runs tests at all** (see B1).

---

## 2. Bugs and risks found

Severity is relative to this service's one job: returning a *wrong or missing* busy range means a double booking downstream, so those rank highest.

### B1. CI never runs the tests, and the Gitea workflow deploys on every push to main
**Status:** Fixed 2026-10-10 on `fix/phase-1-safety-net`, commit `8fd11d1`.
- `.gitea/workflows/build-deploy.yml:1-35` builds, pushes and bumps the gitops tag on `push: main`, with no test step and no gate.
- `.github/workflows/build-sign.yml` (jobs at line 19 onward) builds, scans and signs. It has no test job either, and nothing runs `pest`.
- This is the most likely explanation for "a typo shipped after a green test run". I can't tell which run was "green"; if it was a CI run, it was a *build* going green. The suite also needs a Postgres service container, which neither workflow has.
- **Which workflow is live is unclear.** The recent commits (Kyverno, GHCR signing) point at GitHub, which makes the Gitea file look like a leftover. Please confirm.

### B2. Blackouts can be silently omitted from `/busy` (false "free") — **verified**
**Status:** Fixed 2026-10-10 on `fix/phase-2-busy-correctness`, commit `fa9afe0`.
- `GetBusyRanges.php:29-31` filters blackouts with `whereBetween('date', [$from->toDateString(), $to->toDateString()])`.
- `BusyRangesController.php:19-20` builds `$from`/`$to` with `Carbon::parse($v, $resource->timezone)`. That timezone argument is **ignored when the string carries an offset**, such as `Z`.
- So for a UTC-offset window, `toDateString()` returns the *UTC* date, but blackout `date` is a *resource-local* date.
  - Chicago resource, blackout `2026-07-20 20:00–21:00` local (= `07-21T01:00–02:00Z`). Query `from=2026-07-21T00:00:00Z&to=2026-07-21T12:00:00Z`. Expected: one busy range. **Got `[]`.**
  - Tokyo resource, blackout `2026-07-22 06:00–07:00` local (= `07-21T21:00–22:00Z`). Query `from=2026-07-21T12:00Z&to=2026-07-21T23:00Z`. Expected: one busy range. **Got `[]`.**
- The existing test (`GetBusyRangesTest.php:141-165`) covers only the *safe* direction, where the coarse filter over-fetches and clipping fixes it. The unsafe direction, where the filter under-fetches, is untested.
- **Whether production hits this depends on the client.** If `BookingEngineClient` always sends resource-local offsets or naive strings, this doesn't trigger. A `Z` window, the format every test uses, does trigger it.
- **Fix:** convert `from`/`to` to the resource's timezone *before* taking the date, and widen by ±1 day (`Interval` clips anyway). This goes away entirely if blackouts become instant ranges (§3, F3).

### B3. Naive datetimes mean different things on different endpoints — **verified**
**Status:** Fixed 2026-10-10 on `fix/phase-2-busy-correctness`, commit `ad66e30`.
- `BookingController.php:77-78` parses with `Carbon::parse($v)`, so a naive string is read in the app timezone (UTC).
- `BusyRangesController.php:19-20` reads a naive string in the *resource* timezone.
- A client sending `2026-07-22T09:00:00` to POST (stored as 09:00Z) and then querying busy with naive times (read as Chicago) disagrees by 5 hours. Today that stays latent only if the client always sends explicit offsets.
- **Fix:** require an explicit offset on every datetime input (validation rule or regex) and reject naive strings with a 422. This is a contract change, so check the client first.
- Before bringing Inspector Service back up, confirm BookingEngineClient sends datetimes with an explicit offset; naive strings now return 422.

### B4. `/busy` returns mixed UTC offsets — **verified**
**Status:** Fixed 2026-10-10 on `fix/phase-2-busy-correctness`, commit `ff9cc46`.
- `Interval.php:14-17` returns `$exclusion['start']->max($window['start'])`, which hands back the *window's* Carbon instance when it wins. That instance keeps whatever timezone the request used.
- Naive `from=2026-07-21T10:30:00` on a Chicago resource gave `[{"starts_at":"2026-07-21T10:30:00-05:00","ends_at":"2026-07-21T16:00:00+00:00"}]`. That is two different offsets in one object.
- Instants are still correct, but a consumer that string-compares or truncates gets wrong answers, and `GetBusyRangesTest.php:167-182` only checks the all-UTC case.
- **Fix:** `->utc()` in `BusyRangesController.php:24-27`.

### B5. Idempotency replay ignores the payload — **verified**
- `BookingController.php:60-70`: any request with an existing key returns the stored booking with 200, even when `starts_at`/`ends_at` differ. Reusing key `abc` with a different time returned the *old* booking as a success.
- A consumer bug that reuses a key (a per-appointment key reused after a reschedule) therefore looks like a successful booking at the new time, while the engine holds the old time.
- **Fix:** store a request fingerprint (hash of start, end and metadata). On a mismatch return 422 or 409 with a distinct error code.

### B6. The 23505 "lost the race" branch is effectively dead — **verified**
- `BookingController.php:82-94` handles a unique violation on `(resource_id, idempotency_key)`. But a concurrent retry with the same key necessarily has the same range, and Postgres checks the exclusion index first.
- I reproduced it with two psql sessions: the second insert fails with `23P01 bookings_no_overlap`, **not** 23505. `CreateBooking.php:51` turns that into a **409**.
- So the case idempotency exists for (client times out, retries while the first request is still committing) returns a spurious conflict. No test can see this, because `RefreshDatabase` wraps each test in one transaction and nothing runs concurrently.
- **Fix:** on `23P01`, if an idempotency key was supplied, re-select by key and replay (200) when found. Only otherwise return 409.

### B7. Oversized idempotency key → 500 — **verified**
**Status:** Fixed 2026-10-10 on `fix/phase-4-idempotency`: supplied keys must match `^[A-Za-z0-9_-]{1,128}$`; invalid keys return 422 `validation_failed`. Empty and trailing-newline keys are rejected; `0` is a valid key.
- `BookingController.php:58` reads the header with no validation. A 300-character key hits `varchar(255)` and returns 500 (`SQLSTATE[22001]`).
- **Fix:** validate length and charset (for example `^[A-Za-z0-9_-]{1,128}$`) and return 422.

### B8. Unknown `metadata` keys are silently dropped — **verified**
- `BookingController.php:54-55` declares `metadata` as an array with one validated child. Laravel's `validated()` returns only declared keys, so `metadata: {foo: "bar", appointment_id: 5}` is stored as `{"appointment_id":5}`.
- An empty or absent `metadata` is stored and returned as `[]` (a JSON array), not `{}`. The response type of the field flips between array and object depending on content.
- If the consumer ever sends anything beyond `appointment_id`, it is discarded without an error. Decide the policy: allow free-form with a size cap, or reject unknown keys with 422.

### B9. Out-of-range IDs return 500 — **verified**
- `GET /api/resources/99999999999999999999/busy` gives `SQLSTATE[22003] … out of range for type bigint`, status 500, not 404. This applies to every route with `{resource}`, `{booking}` or `{blackoutDate}`.
- `bootstrap/app.php:25-27` only maps `NotFoundHttpException`.
- Low likelihood (the consumer sends real IDs) but trivial to fix with a route pattern (`[0-9]{1,18}`).

### B10. Inconsistent error and response shapes — **verified**
**Status:** Fixed 2026-10-10 on `fix/phase-3-contract`. Hand-written `docs/openapi.yaml` and real-response feature validation cover the normalized contract. All API errors have `message` and `code`; conflict `error` remains. Ownership mismatches return 404; unknown routes use `route_not_found`, missing records use `not_found`. Booking and blackout response timestamps use UTC seconds with `+00:00`. POST bookings/blackouts return complete stored objects matching GET. Resource creation returns 201; existing resources return 200. See `docs/phase-3-contract-changes.md` for the consumer migration table. The findings below describe the original behavior.

| Concern | Where | Inconsistency |
|---|---|---|
| Error key | `BookingController.php:96` | 409 returns `{"error": …}`. Everything else (401, 403, 404, 422) uses `{"message": …}`. |
| Ownership mismatch | `BookingController.php:104-106`, `BlackoutDateController.php:61` | Returns **403** where a missing record returns 404. This confirms that a record id exists on another resource. |
| 404s | `bootstrap/app.php:25-27` | A catch-all for any `NotFoundHttpException`. Message is the same for a typo'd route and a missing resource, so clients can't tell them apart. |
| Booking timestamps | `CreateBooking.php:58-70` vs `BookingController.php:37-41` | POST returns no `created_at`/`updated_at`; GET returns them. |
| Timestamp format | busy vs bookings | Busy: `2026-07-20T14:00:00+00:00`. Bookings: `2026-07-21T15:00:00.000000Z`. Same instants, two formats. |
| Blackouts | `BlackoutDateController.php:46-48` vs `:24-26` | POST returns only the fields that were sent (no `start_time`, `end_time` or `note` keys); GET returns all of them as `HH:MM:SS`, though input is `HH:MM`. |
| Status codes | `ResourceController.php:31-37` | `POST /resources` returns 200 for both create and find (it carries a `created` flag). |
| Validation | all | Default Laravel 422 shape, which is fine, but there is no machine-readable `code` anywhere. |

None are wrong in isolation. Together they are what a consumer's HTTP mocks drift from.

### B11. `POST /resources` silently ignores a different timezone — **verified**
- `ResourceController.php:21-29` uses `firstOrCreate` with `timezone` only in the "create" attributes. Posting an existing resource with a *different* timezone returns 200 with the *old* value and `created:false`.
- `PUT /resources/{id}` (`:43-57`) does change it. That silently shifts every existing blackout's UTC meaning, because blackouts are stored as local wall-clock time (**unverified**, from the code), while bookings are absolute instants and don't move.
- Also: `firstOrCreate` can race on `unique(external_type, external_id)` and surface as a 500 (**unverified**, standard check-then-insert race).

### B12. Test gaps for the failure modes you described
**Status:** Phase 3 controller coverage added 2026-10-10 on `fix/phase-3-contract`: GET bookings executes the real Postgres overlap predicate, verifies resource isolation, half-open boundaries, offset bounds, full unclipped ranges, empty lists and invalid windows. ResourceController covers create/find statuses, timezone update, validation and missing resources. Spec tests validate all service operations. Full suite: 126 tests passed, 518 assertions on Postgres 17.11. The concurrency/savepoint coverage and overlap-helper refactor remain deferred; B5-B9, B11 and B13 are unchanged. The findings below describe the original gaps.
- **Raw SQL with no test execution:** `BookingController.php:23` (`whereRaw('during && ?::tstzrange', …)` in `index`). There is no test for `GET …/bookings` at all. I ran it manually and it works today, but a future edit to the binding wouldn't fail any test. `CreateBooking.php:35-47` and `GetBusyRanges.php:24` *are* executed on real Postgres by existing tests.
- **Untested controllers:** `ResourceController` (store and update) has zero tests. The 23505 branch and the savepoint comment at `CreateBooking.php:32-33` can't be exercised under `RefreshDatabase`.
- **Passes, but fails in prod:** B2, B3 and B4 all pass today because every datetime in the suite is UTC with a `Z`, and `IntervalTest` uses same-timezone Carbons.
- **Local setup:** `phpunit.xml` has its DB settings commented out and relies on `.env.testing` plus a manually started Postgres. Running without one gives 37 errors that look like test failures.
  **Status:** Setup fixed 2026-10-10 on `fix/phase-1-safety-net`, commit `a4838ab`.
- **Scaffold tests:** `ExampleTest` (hits `/`, the Laravel welcome page) and `tests/Unit/ExampleTest.php` are noise.

### B13. Smaller items
- **Per-request write on every call** — `AuthenticateApiKey.php:35-36` runs `UPDATE api_keys` on every request. That causes row-lock contention on a single hot row under concurrent load. Only update when `last_used_at` is older than a minute.
- **No rate limiting** — `routes/api.php` has no throttle. A client retry loop is unbounded. Low risk with one trusted consumer, but cheap insurance.
- **No `blackout_dates` index** — the migration creates the FK with no `(resource_id, date)` index (Postgres does not index foreign keys). Invisible at current volume. I did not inspect `pg_indexes` for this table, only the migration.
- **Unused index** — `bookings (external_type, external_id)` (`create_bookings_table.php:32`) isn't used by any query I found. Those two columns are also denormalised copies of `resources` and nothing keeps them in sync.
- **Unbounded lists** — `BookingController.php:33` and `BlackoutDateController.php:24` return every row, with no pagination or limit.
- **Range text parsing** — `Booking.php:29-41` parses Postgres range text by stripping brackets and splitting on a comma. It is fine for bounded ranges, which is all the app creates. But the constraint doesn't forbid `infinity` or empty ranges, so any future writer (ICS import, manual SQL) can produce rows this parser mis-reads. Consider `CHECK (NOT isempty(during) AND NOT lower_inf(during) AND NOT upper_inf(during))`.
- **Blackouts aren't enforced on booking creation** — `CreateBooking` only checks other bookings. `/busy` shows the union, so the oracle is consistent, but the engine will accept a booking inside a blackout. That is probably intentional for an oracle; I'm flagging it so it's a *decision* rather than an accident.
- **No overnight blackouts** — `BlackoutDateController.php:42` (`after:start_time`) rejects 22:00–02:00 with a 422. Verified.
- **Duplicate blackouts** are accepted. Harmless, because the merge absorbs them.
- **Dead code** — `InvalidAvailabilityException` (unused; a fossil of the old availability-rule design). `/api/user` with Sanctum (`routes/api.php:11-13`), `personal_access_tokens`, the `users` table and the welcome page are unused surface.
- **Debug mode** — `docker-compose.yml:24-25` sets `APP_ENV: production` with `APP_DEBUG: "true"`. That is the local compose file; I can't see the real deployment config. If prod inherits it, every 500 returns SQL, bindings and file paths (I saw this in the 500s above). **Please check the gitops values.**
- **No queue worker or scheduler** — nothing in `docker/` runs `queue:work` or `schedule:run`. This matters for hold expiry and outbox (§3).
- **Telescope** — the package is dev-only and registered only in `local`, so the production image doesn't ship it. This is fine. Its migration still creates `telescope_entries` in production, which is harmless.
- **DST edge cases** — **unverified**. `GetBusyRanges.php:58-59` converts nonexistent or ambiguous local times (for example 02:30 on a spring-forward day) via `Carbon::parse($date $time, $tz)`, which resolves silently. Full-day handling at `:55-56` looks right (`addDay()->startOfDay()` gives 23- and 25-hour days). There is no test for either.

---

## 3. Candidate features

Legend: **Fits oracle?** = stays a pure busy-time store/merge, with no availability rules and no free-slot computation.

### F1. Slot holds (`status`, `expires_at`)
- **Fits:** yes. A hold is a bounded-lifetime busy range. The consumer still decides what is free.
- **Schema:** `bookings.status` (`held|confirmed`) and `expires_at timestamptz NULL`, with `CHECK (status='confirmed' OR expires_at IS NOT NULL)`. Keep the existing exclusion constraint unchanged: one table, one constraint, so held and confirmed rows block each other.
- **Endpoints:** `POST /bookings` accepts `status=held` and `ttl_seconds`. New `POST /bookings/{id}/confirm`. Existing `DELETE` releases.
- **What could go wrong:**
  - A constraint can't reference `now()`. An expired hold keeps blocking until it is deleted, so inserts get a spurious 409.
  - Reaping needs either a scheduler, which this deployment does not have, or lazy cleanup. The lazy option is `DELETE … WHERE status='held' AND expires_at <= now()` inside the same transaction as each insert for that resource. I'd choose that.
  - Use the database clock, not PHP's.
  - Confirm-after-expiry races: confirm must be `UPDATE … WHERE status='held' AND expires_at > now()` and return 410 if it affects no rows.
  - `/busy` must filter out expired holds (`expires_at > now()`).
  - Do **not** add a held/confirmed flag to merged busy output without a new, opt-in field; it changes the current contract.
- **Open question:** does Inspector Service have a multi-step checkout that needs holds? If not, this is speculative.

### F2. Atomic reschedule (`PATCH /resources/{r}/bookings/{id}`)
- **Fits:** yes.
- **Schema:** none. A single `UPDATE bookings SET during = ?::tstzrange, updated_at = now() WHERE id = ? AND resource_id = ?`.
- **Key property:** Postgres exclusion constraints on UPDATE do not conflict a row with its own old value, so moving a booking to an overlapping new range works. The consumer currently has to DELETE then POST, which can lose the slot to someone else between the two calls.
- **Endpoints:** `PATCH` with `starts_at`/`ends_at`, mapped to 409 on `23P01` exactly like create. It also needs a `GET` for a single booking, which doesn't exist.
- **What could go wrong:** it is a new raw SQL statement, so it needs its own test against real Postgres, which is the failure class you've already had. Lost updates between two PATCHes need `If-Match` or a version column. Do **not** support moving across resources in v1, because that needs locking two resources. Idempotency semantics need defining.

### F3. Blackouts as a single range row; recurring blackouts
- **Single range row — fits, and recommended.**
  - **Schema:** `blackouts(id, resource_id, during tstzrange, note)` with a GiST index on `(resource_id, during)`, and the same CHECK as above. Backfill from existing rows in SQL: `(date + start_time) AT TIME ZONE resources.timezone`.
  - **What it fixes:** it deletes B2 outright, removes the resource-timezone-change hazard (B11), allows overnight and multi-day blackouts, and makes the busy query one overlap predicate.
  - **Cost:** the consumer now computes local midnight and DST itself and sends instants. That is arguably the right owner, since it knows the business meaning of "closed Dec 25".
  - **Compatibility:** keep accepting the old `date/start_time/end_time` shape on write for a deprecation window. Convert once, at write time, using the timezone at that moment.
- **Recurring blackouts — this breaks the oracle constraint.**
  - "Every Monday 12–13" is an availability rule. Storing an RRULE and expanding it at read time puts a rule engine and its DST/exception semantics inside the oracle.
  - **Alternative that keeps the model:** the consumer expands recurrences into concrete range rows over a horizon (say 12 months) via a bulk endpoint, and the engine remains rule-free.
  - If you want it anyway, treat it as a deliberate change to the model, not a feature.

### F4. ICS import / export
- **Import — conditionally fits.** An external calendar is just another busy source, but the common design (the engine fetches a URL) is risky.
  - Outbound fetching needs a scheduler (not present), SSRF protection and a staleness policy. A stale feed means a *silent* double booking.
  - Real ICS needs `RRULE` expansion, `VTIMEZONE`, all-day semantics, `TRANSP:TRANSPARENT` and cancelled instances. That is a recurrence engine, the same boundary problem as recurring blackouts.
  - **Safer variant:** the consumer fetches and parses, then pushes concrete ranges with `PUT /resources/{r}/external-busy/{source}` (replace-set semantics). The engine stays rule-free and does no outbound I/O.
- **Export:** read-only and easy to render, but calendar clients can't send `X-API-Key`, so it needs a new tokenised, unauthenticated URL, which is a new auth surface. Low value for a service with one server-to-server consumer.
- **Verdict:** defer. If needed, build the push-only variant.

### F5. Buffer time before/after bookings
- **Fits only in one form.**
  - A per-resource buffer applied at read time inside `/busy` is a business rule inside the oracle, and `GET bookings` and `/busy` would then disagree.
  - Acceptable form: the consumer pads the range it sends. If you want the engine to keep the *real* time separate, add a generated `during_padded` column and put the exclusion constraint on it.
- **What could go wrong:** retroactive changes to buffer settings, asymmetric buffers between booking types, and merged busy output losing boundaries (10–11 and 11–12 already merge into one range).
- **Verdict:** low value unless a real requirement exists. Prefer consumer-side padding.

### F6. Batch busy endpoint
- **Fits:** yes, perfectly.
- **Endpoint:** `POST /busy` with `{resource_ids:[…], from, to}`, returning `{ "<id>": [ranges] }`. Use POST to avoid URL length limits.
- **Schema:** none. Two queries total (`resource_id IN (…)` for bookings and for blackouts), grouped in PHP; the per-resource timezone logic stays as it is.
- **What could go wrong:** cap the id count (say 100) and the window (say 366 days). Decide between partial results and an all-or-nothing 404 for unknown ids. Do **not** add a "free" or "available" flag to the response.
- **Value** depends on how often the consumer loops over resources. I can't see that.

### F7. Outbox + webhooks
- **Fits:** yes, with no rules involved.
- **Value is low today.** The sole consumer makes every write, so it already knows what happened. Events become interesting only with a second writer (ICS, admin tools) or with hold expiry.
- **Cost:** the outbox table is the easy part. This deployment has no queue worker, and delivery also needs signing, retries, replay and a safe target allowlist.
- **Verdict:** defer until holds or a second writer exist.

### F8. OpenAPI, per-consumer keys + rate limits, `/metrics`
- **OpenAPI — do it, and use it as a test, not just documentation.** Effort is S–M. The consumer's tests mock HTTP, so nothing currently checks that the mocks match the real service. Write the spec by hand, then validate real responses against it in this repo's feature tests (for example with `league/openapi-psr7-validator`; I haven't checked fit with the current Laravel version). This also forces the response shapes in B10 to be decided once.
- **Per-consumer keys:** the schema already supports N keys with a name. What's missing is *scoping* (which resources a key may touch). That only matters with a second consumer. Defer.
- **Rate limits:** the realistic threat is a retry storm from the one consumer, not abuse. A flat per-key throttle (`RateLimiter::for('api', fn ($r) => Limit::perMinute(600)->by($key))`) is S effort. Skip the per-key configurable limits for now.
- **`/metrics`:** S effort, and the homelab already runs kube-prometheus-stack. Most useful signals are request rate and latency by route, the 409 rate, and DB errors. It must not be reachable through the public ingress. Restrict it with a NetworkPolicy or a separate port.

### Not on your list, but worth doing
1. **CI test job with a Postgres service container, gating the deploy** (B1). Highest value for the lowest effort in this review.
2. **Single overlap helper.** Put the `during && ?::tstzrange` predicate and the range-literal formatting in one tested place (a `Booking::scopeOverlapping()` plus a small range-literal helper) instead of three hand-built copies (`CreateBooking.php:25-29`, `GetBusyRanges.php:24-26`, `BookingController.php:23-29`). That removes the drift class directly.
3. **Idempotency fingerprinting** (B5-B7). S effort.
4. **Error envelope with a machine-readable `code`** (`booking_conflict`, `idempotency_mismatch`, …) so the client stops matching on message strings. Do it additively (keep `error` on 409 alongside `message`) so the client isn't broken.
5. **A real cross-service contract test.** Run `BookingEngineClient` against a booted instance of this service (the `docker-compose.yml` already exists) in the consumer's CI, or at least share golden JSON fixtures. This is the only thing that closes the "consumer mocks the HTTP layer" gap.
6. **Soft-cancel instead of hard delete** (`BookingController.php:108`). Today a cancelled booking is gone with no audit trail. Not needed for the oracle itself, but it becomes necessary for webhooks and for answering "who cancelled this". Medium-low value.
7. **Lookup by `appointment_id`.** `metadata.appointment_id` is validated but never queried. If the consumer ever needs to find a booking from its own appointment, add `GET …/bookings?appointment_id=` with an expression index. Ask the consumer first.
8. **Sanity bounds on booking duration** (for example ≤ 366 days). Verified: the engine currently accepts a booking from 2026 to 9999 or one in 2001. Frame these as sanity limits, **not** business rules, since "minimum notice" or "maximum slot length" belong to the consumer.

### Things that would break the oracle model
- Recurring blackouts stored as rules (F3).
- ICS import where the engine fetches and expands RRULEs (F4).
- Per-resource buffer applied inside `/busy` (F5).
- Any `free`/`available`/"next open slot" field or endpoint, including as a convenience on the batch endpoint (F6).
- Booking-validity rules such as working hours, minimum notice, or maximum duration as business policy.

---

## 4. Ranked improvements and features

Value = benefit to correctness and to the consumer. Effort: S (≤ 1 day), M (days), L (a week or more). Risk = chance of breaking something that works.

| # | Item | Value | Effort | Risk | Depends on |
|---|---|---|---|---|---|
| 1 | CI: run Pest against Postgres on PR and push; gate deploy on it (B1, B12) | High | S | Low | none |
| 2 | Fix busy-query correctness: blackout date filter (B2), UTC-normalise output (B4), require explicit offsets (B3) | High | S | Low–Med (B3 is a contract change) | #1 to guard it; client behaviour check for B3 |
| 3 | OpenAPI spec + response/error normalisation (B10) + spec-validated feature tests | High | M | Low if additive | #1; consumer coordination |
| 4 | Idempotency hardening: key validation, payload fingerprint, 23P01 replay (B5-B7) | Med–High | S | Low | #1 |
| 5 | Atomic reschedule (F2) + single-booking GET | High | S–M | Low | #1, #3 (shape), overlap helper |
| 6 | Single overlap helper + tests for `GET bookings` and `ResourceController` (B12) | Med | S | Low | #1 |
| 7 | Blackouts as range rows (F3, first half) | High | M | Med (data migration, API compatibility) | #1, #2 |
| 8 | Batch busy endpoint (F6) | Med | S | Low | #2, #3 |
| 9 | Cross-service contract test with the real client | High | M | Low | #3 |
| 10 | Throttle + `last_used_at` write reduction + metrics (B13, F8) | Low–Med | S | Low | none |
| 11 | Slot holds (F1) | Med (needs a consumer use case) | M | Med (expiry semantics, no scheduler) | #4, #5 |
| 12 | Resource/route hardening: tz-conflict signal on `POST /resources` (B11), bigint route pattern (B9), `metadata` policy (B8), CHECK constraints on `during` | Med | S | Low | none |
| 13 | Soft-cancel + audit trail | Low–Med | M | Low | none |
| 14 | Outbox + webhooks (F7) | Low now | L | Med | #11 or a second writer |
| 15 | ICS push variant (F4) | Low | L | High | #7 |
| 16 | Buffer time (F5), recurring blackouts (F3, second half), ICS pull | Low | M–L | **Breaks oracle model** | not recommended |

---

## 5. Recommended order for the first three

**1. CI with Postgres, gating deploy (#1).**
Every other change here is only as safe as the thing that runs the tests. Both of your past failures (SQL drift, typo after a green run) are things CI would have caught. It's a service container plus `composer install` plus `pest`. It also makes `build-sign.yml` depend on a green test job, so unsigned or untested images never reach the digest-bump PR. First because it's small, it's independent of everything else, and it protects every step after it.

**2. Fix busy-query correctness (#2: B2, B4, and B3 once the client is checked).**
This is the only finding that can produce a *wrong answer*, and a wrong answer from a busy oracle is a double booking. B2 is a one-line direction fix plus regression tests for both directions (Chicago and Tokyo cases, `Z` and offset windows). Do the tests first and watch them fail. B4 is a one-word fix (`->utc()`). B3 changes what the client may send, so grep `BookingEngineClient` for how it formats datetimes before turning on strict validation. If it already sends explicit offsets, ship it; if not, change the client first.

**3. OpenAPI spec with spec-validated tests and normalised errors (#3).**
**Status:** Implemented 2026-10-10 on `fix/phase-3-contract`, with separate regression-first commits for B10 concerns. `league/openapi-psr7-validator` 0.24 resolves with Laravel 13 without updates/removals to existing dependencies and validates real test responses. Timestamp replacement is an intentional breaking change authorized while the only consumer is offline; update BookingEngineClient before bringing it online. This supersedes the additive-timestamp suggestion in the original recommendation below.
You told me the consumer's tests mock the HTTP layer, so a shape change here can't fail anything over there. A spec validated against real responses in this repo's tests is the cheapest way to make the contract executable, and writing it forces the inconsistencies in B10 to be resolved once instead of ten times. Ship the additive subset first (a `code` field, a `message` alongside `error`, consistent timestamps in new fields) and keep old keys until the client has migrated. Doing this *before* reschedule, holds and range blackouts means each new endpoint is born with a validated shape instead of adding another variant.

After these three, take #4 (idempotency) and #5 (atomic reschedule) next. Both are small, both remove a real two-call window in the consumer's flow, and both rely on the test infrastructure that steps 1-3 establish. Blackout range rows (#7) are the best "bigger" structural change, but wait until #2's tests exist so the migration can be checked against them.

---

## Housekeeping notes from this session
- No application, test or config files were modified. Throwaway probe scripts live in the session scratchpad, outside the repo; the only repo change is this `docs/review.md`.
- To run the suite I started Docker Desktop and a throwaway `postgres:16` container (`be-review-pg`, trust auth, port 5432). I then stopped the container and quit Docker Desktop. The probes wrote only to the disposable `booking_engine_test` database, inside rolled-back transactions, except the two-session race test, which inserted and then deleted one resource and one booking.
- `git status` was clean before and shows only `docs/` now.

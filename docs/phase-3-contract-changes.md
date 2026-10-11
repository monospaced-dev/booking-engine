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

# Running tests

Pest uses real Postgres 17, including the range/exclusion constraints. PHPUnit
forces the `pgsql` connection and `booking_engine_test` database and ignores
`DB_URL`; do not use an application database. Host, port and credentials can be
overridden in the shell. Clear any cached application configuration first.

Start a disposable database (choose another host port if 5432 is occupied):

```sh
docker run --detach --rm --name booking-engine-tests \
  --env POSTGRES_DB=booking_engine_test --env POSTGRES_USER=root \
  --env POSTGRES_PASSWORD=testing --publish 127.0.0.1:5432:5432 postgres:17
docker exec booking-engine-tests pg_isready -U root -d booking_engine_test
```

Wait until `pg_isready` reports accepting connections, then run:

```sh
composer install --prefer-dist --no-interaction
php artisan config:clear
DB_PASSWORD=testing vendor/bin/pest
```

The suite bootstrap checks the connection before executing tests. An unavailable
database exits with one setup message instead of repeated test errors. Tests
migrate and reset the test database automatically; no separate migration step is
required. The GitHub test job provisions the same database as a service and gates
the image build, scan, push and signing steps.

Remove the disposable database when finished:

```sh
docker stop booking-engine-tests
```

## API contract checks

`docs/openapi.yaml` is maintained by hand. Feature tests convert the actual
Laravel HTTP responses (status, headers and raw JSON body) to PSR-7 and validate
them with `league/openapi-psr7-validator` 0.24. Its dependencies resolve with
Laravel 13 and PHP >=8.4.1 without updating existing packages. The tests exercise
all service operations and their success, authentication, not-found, validation,
conflict and unhealthy-database responses, including bodyless 204 responses.
Negative checks verify the validator rejects missing fields, wrong types and
legacy timestamp/error shapes. Run them with the same Postgres setup above:

```sh
DB_PASSWORD=testing vendor/bin/pest tests/Feature/OpenApiContractTest.php tests/Feature/GetBookingsTest.php
```

Use `assertOpenApi('/resources/{resource}/bookings', 'post')` on a test response.
Pass the spec path template without `/api` and the HTTP method. Keep semantic
assertions too: schema validation alone does not check ownership, interval
boundaries, persistence, or whether `created` matches the returned status.
The metadata schema deliberately retains B8's empty-array/object behavior;
`anyOf` also avoids the validator treating an empty array as both PHP shapes.


## Idempotency races

`tests/Integration/BookingIdempotencyRaceTest.php` uses `DatabaseMigrations`,
not `RefreshDatabase`, so separate processes can see committed fixtures. It
requires PHP `proc_open` and direct access to the same Postgres test database.
The worker waits for `pg_blocking_pids` to confirm that the HTTP INSERT is
blocked on its uncommitted booking before committing; a bounded timeout fails
the test if this synchronization does not occur. The real 23P01 and 23505
exceptions are observed and rethrown, with no mocked errors. Run these tests
alone with the usual DB settings, or via the full suite (Integration follows
Feature). Each case cleans up its worker and fixtures and resets migrations.

```sh
DB_PASSWORD=testing vendor/bin/pest tests/Integration/BookingIdempotencyRaceTest.php
```

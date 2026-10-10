# Running tests

Pest uses real Postgres 16, including the range/exclusion constraints. PHPUnit
forces the `pgsql` connection and `booking_engine_test` database and ignores
`DB_URL`; do not use an application database. Host, port and credentials can be
overridden in the shell. Clear any cached application configuration first.

Start a disposable database (choose another host port if 5432 is occupied):

```sh
docker run --detach --rm --name booking-engine-tests \
  --env POSTGRES_DB=booking_engine_test --env POSTGRES_USER=root \
  --env POSTGRES_PASSWORD=testing --publish 127.0.0.1:5432:5432 postgres:16
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

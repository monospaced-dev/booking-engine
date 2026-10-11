# Phase 1: safety net

Applied only B1 and B12 setup on `fix/phase-1-safety-net`.

## Suite results

- Before any repository changes: `DB_PASSWORD=phase1-test vendor/bin/pest`
  passed 51 tests, 104 assertions (846 ms), against a disposable Postgres 16
  container with database `booking_engine_test`.
- After: `DB_PASSWORD=phase1-test vendor/bin/pest --fail-on-risky`
  passed 51 tests, 116 assertions (908 ms), without risky tests. Two
  infrastructure regressions replace the two scaffold ExampleTests.
- Pint passed; workflow YAML parsed and its image-job dependency was checked;
  `git diff --check` passed.
- The throwaway container `be-phase1-pg` was removed after verification.

## Regression evidence and changes

**B1:** The regression first failed because GitHub had no `test` job. It passed
after adding Pest on PHP 8.4 with `pdo_pgsql`, Composer dev dependencies, and a
health-checked `postgres:16` service. The existing `image` matrix job has
`needs: test`, gating both images' build, scan, push and signing steps. The
setup-php action is pinned to the verified commit for 2.35.5. The already staged
Gitea workflow deletion was included in this fix.

**B12 setup:** The regression first failed because the unavailable database
produced no single setup diagnostic. A separate feature-suite run confirmed
37 database errors before the fix. After the fix, the regression passed. A
final check after dropping only the disposable `booking_engine_test` database
exited 1 with exactly this message and no test execution:

```text
Test database unavailable: start Postgres 16 with database booking_engine_test; check DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD (see .env.testing and docs/testing.md).
```

PHPUnit explicitly selects Postgres and the test database, ignores `DB_URL`,
and permits host/port/credential overrides. The bootstrap also rejects cached
configuration pointing elsewhere before any migrations. Local setup and cleanup
commands are in `docs/testing.md`. Both scaffold ExampleTests were deleted.

Each fix is in its own commit with its B-number in the message.

## Consumer contract and remaining work

There are no consumer contract changes: accepted requests, status codes, response
keys and response values are identical before and after Phase 1. No application
code or migrations changed. `docker-compose.yml` was left alone.

B2–B11, the controller coverage portion of B12, B13 and all candidate features
remain deferred to their requested phases. No additional out-of-phase findings
were discovered. The pre-existing untracked `docs/review.md` was read in full
and left unchanged and untracked.

GitHub Actions itself has not run: local verification covers Pest and workflow
structure, not an executed hosted CI run. No push, merge, tag, deployment or
homelab-gitops access was performed.

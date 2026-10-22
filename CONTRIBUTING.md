# Contributing

Thanks for considering a contribution.

## Reporting a bug

Open an issue with the Laravel and PHP versions, the database engine and its version, the migration, and what the package reported. A wrong verdict is best shown with the statement, the size of the table and what the engine actually did; a failing Pest test is the most useful report.

## Proposing a change

1. Fork the repository and create a branch from `master`.
2. Install the dependencies: `composer install`.
3. Make the change, with tests for any new behaviour. A rule comes with a test of each engine's verdict in `tests/Feature/Rules`, run on every engine.
4. Make sure everything is green before opening the pull request, on SQLite and on the engine your change concerns:

```bash
composer test
STRICT_MIGRATIONS_TEST_DB=pgsql composer test
composer analyse
composer lint
```

Keep pull requests focused: one change, its tests and, when it affects users, a line in `CHANGELOG.md` under *Unreleased*.

## Design rules

- A verdict is what the engine does, checked on the engine: a lock the rule claims is a lock the engine takes. `bench/measure.php` measures it.
- No false positive on a small table: a tool that is turned off after its third false alarm protects nobody.
- Every finding explains why and gives the Laravel code that does the same thing safely.
- The package never writes to the database, except the lock timeout of the session.

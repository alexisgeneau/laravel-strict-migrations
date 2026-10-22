# Changelog

## 0.1.0 - 2026-10-23

First release, tested with Laravel 12 and 13 on PHP 8.2 to 8.5, against PostgreSQL, MySQL, MariaDB and SQLite.

- A guard in `php artisan migrate`: each migration is run dry against the real database before it runs, and refused before any of its statements is sent when it would lock a large table or break the code still running during a deployment. Each finding names the statement, the table and its size, and gives the safe way to write it.
- Rules for PostgreSQL, MySQL and MariaDB on indexes, constraints, foreign keys, `NOT NULL`, type changes, defaults, generated and auto-increment columns, dropped and renamed columns and tables, data changes and maintenance statements, and the statements SQLite cannot run on a table that holds rows.
- A risk weighed by the size of the table: a lock on a table at or below `small_table_rows` is a warning, and nothing is judged on a table created earlier in the same run.
- A lock timeout on every migration, restored afterwards, with retries when the migration can run again safely, and none for concurrent index builds.
- `Schema::safetyAssured()` for a statement and `#[SafetyAssured]` for a migration, `check_after` to leave the existing migrations alone, `disabled_rules`, and the `block`, `warn` and `off` modes.
- `migrate:check` for CI, with console, GitHub and JSON output, and `strict-migrations:install`.

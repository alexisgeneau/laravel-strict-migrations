# Laravel strict migrations

Refuse the Laravel migrations that would lock a production table or break the code still running during a deployment, with the safe way to write them.

> **Status:** 0.1.0, tested with Laravel 12 and 13 on PHP 8.2 to 8.5, against PostgreSQL, MySQL, MariaDB and SQLite. Until 1.0, a minor version may still add rules or change their wording; the changelog says so when it happens.

```
php artisan migrate --force

   UNSAFE  2026_10_23_091500_add_customer_index_to_orders · PostgreSQL 16.15

  ⨯ Building orders_customer_id_index blocks every write to orders until the index is built.
    orders: more than 10,000 rows
    create index "orders_customer_id_index" on "orders" ("customer_id")

    Build it concurrently, which does not block writes, in a migration that runs outside a transaction:

        public $withinTransaction = false;

        Schema::table('orders', function (Blueprint $table) {
            $table->index('customer_id')->online();
        });

    A concurrent build that fails leaves an invalid index behind: drop it before running the migration again.

  ⨯ Dropping orders.notes breaks the code that still reads or writes it while the deployment runs.
    alter table "orders" drop column "notes"

    Stop using notes first: remove it from $fillable, $casts, queries, resources and factories, and deploy that.
    Drop the column in a later deployment, marking that migration:

        return new #[SafetyAssured('notes unused since the previous release')] class extends Migration

  Checked and harmless here? Wrap the statement in Schema::safetyAssured(fn () => ...), or mark the whole migration: new #[SafetyAssured('why')] class extends Migration.
  STRICT_MIGRATIONS=warn reports without refusing.

  2026_10_23_091500_add_customer_index_to_orders ................... REFUSED
```

Nothing of the refused migration ran, the ones before it stay applied, and `migrate` exits with a failure, so the deployment stops there.

## Why

A migration that takes 20 ms on a development database can make a production table unavailable for minutes, and nothing in the code says so. Three things hide behind an ordinary `Schema::table()`:

- **Locks.** Building an index, changing a type, setting `NOT NULL` on PostgreSQL or validating a foreign key holds a lock while every row is read or rewritten. The queries of the application wait meanwhile.
- **The lock queue.** A statement that needs an exclusive lock waits behind the longest running transaction, and every query that arrives after it waits behind it, reads included. A migration can take a site down without doing anything, just by waiting.
- **The code still running.** During a rolling deployment the previous release keeps serving requests, and queue workers keep the old code until they restart. A dropped or renamed column breaks them before they are gone.

Rails teams install [strong_migrations](https://github.com/ankane/strong_migrations) for this. Laravel generates the same statements, runs `php artisan migrate --force` in every deployment script, and had no equivalent.

## How it differs

The package does not read your migration files. It **runs each migration dry against the real database**, records the statements Laravel's grammar would send, and judges them knowing the server, its version and the size of every table they touch:

- raw statements are judged like the ones `Schema` builds, and the code takes the branches it takes for real: loops, conditions, `Schema::hasColumn()`;
- an index on a table of 300 rows is a warning, on a table of 3 million it is refused; a table created by the same run can be changed freely;
- it runs inside `php artisan migrate`, at deployment time, not only in CI;
- every migration runs with a lock timeout, and is retried when nothing of it was applied;
- every finding comes with the Laravel code that does the same thing safely.

## Requirements

- PHP 8.2 or newer
- Laravel 12.23 or newer, or Laravel 13
- PostgreSQL 12+, MySQL 8.0+ or MariaDB 10.6+; SQLite gets the checks that apply to it, other drivers are not analyzed

## Installation

```bash
composer require alexisgeneau/laravel-strict-migrations
php artisan strict-migrations:install
```

The install command publishes `config/strict-migrations.php` with `check_after` set to the current time: the migrations that already exist are never judged, only the ones you write from now on. The guard is active as soon as the package is installed, everywhere but in the `testing` environment, where `RefreshDatabase` replays every migration for each test run.

## On `php artisan migrate`

Before its line is printed and anything is sent, each pending migration is run dry and analyzed. What follows depends on the most serious finding:

| Finding | Shown | Effect |
| --- | --- | --- |
| `UNSAFE` | always | the migration is refused, nothing of it runs, `migrate` fails |
| `WARN` | always | the migration runs: a risk worth knowing, such as a lock on a small table |
| `ASSURED` | always | the migration runs: a risky statement you vouched for |
| `NOTE` | with `-v` | the migration runs: something to know, such as an index built in place on MySQL |

`STRICT_MIGRATIONS=warn` turns refusals into reports for a deployment that must go through, and `STRICT_MIGRATIONS=off` turns the package off. `migrate --pretend` is left alone, and so are rollbacks unless `check_down` is on: a `down()` usually drops what `up()` created, and a rollback is rarely the moment to be stopped.

## What is checked

Each rule knows how its engine carries the statement out; the verdict is what the statement blocks, on which engine.

| Statement | PostgreSQL | MySQL and MariaDB | Rule |
| --- | --- | --- | --- |
| Index on an existing table | blocks writes for the whole build: build it concurrently | built in place, writes continue | `index` |
| FULLTEXT or SPATIAL index | blocks writes | blocks writes while the table is rebuilt | `index` |
| `create index concurrently` inside the migration's transaction | fails | — | `index-in-transaction` |
| Unique constraint, primary key | blocks reads and writes while the index is built: build it concurrently, then attach it | primary key rebuilt in place; dropping one alone copies the table | `unique-constraint`, `primary-key` |
| Foreign key | blocks writes on both tables while every row is checked: `->notValid()`, validate later | copies the table while foreign key checks are on: `Schema::withoutForeignKeyConstraints()` | `foreign-key` |
| Check constraint | blocks reads and writes while every row is checked: `not valid`, validate later | copies the table | `check-constraint` |
| `NOT NULL` on an existing column | blocks reads and writes while every row is scanned, unless a validated check already proves it | rebuilt in place, and fails if a row holds `NULL` | `not-null` |
| Type change | rewrites the table, unless the types are binary compatible (longer `varchar`, `varchar` to `text`, larger `numeric`, `timestamp` to `timestamptz` in UTC) | copies the table, unless MySQL changes it in place (a longer `varchar` within the same length prefix, an `ENUM` value appended, a new default) | `column-type` |
| Column with a volatile default (`gen_random_uuid()`, `(uuid())`), stored generated column, identity or auto-increment column | rewrites the table | copies the table | `column-default`, `column-rewrite`, `auto-increment` |
| `NOT NULL` column without a default, on a table that holds rows | fails | `date` and `datetime` fail in strict mode | `not-null-without-default` |
| Dropped or renamed column, renamed or dropped table | breaks the code still running | breaks the code still running | `drop-column`, `rename-column`, `rename-table`, `drop-table` |
| `drop index` | waits for the queries on the table, and those after wait behind it: drop it concurrently | — | `drop-index` |
| `update`, `delete`, `insert … select` | rows stay locked until the migration's transaction commits; after a schema change of the same migration, the table lock does too | one long statement | `data-change`, `schema-and-data` |
| `truncate`, `vacuum full`, `cluster`, `reindex`, `refresh materialized view`, `lock table`, `convert to character set`, a new storage engine | blocks the table for its whole run | blocks the table for its whole run | `truncate`, `table-rebuild`, `reindex`, `refresh-materialized-view`, `lock-table` |
| `json` column | jsonb can be indexed and compared, json cannot | — | `json` |
| A statement it cannot read | refused until you check it | refused until you check it | `unread` |

On SQLite, which locks the whole database for every write, the rules are the statements that fail: a `NOT NULL` column without a default, a non-constant default or a stored generated column added to a table that holds rows, a `UNIQUE` column. A table that Laravel rebuilds to alter it is a note.

**The size of the table decides between a refusal and a warning.** Each table a risky statement touches is counted up to `small_table_rows` + 1, by a query that never reads more rows than that: at or below the threshold, a lock is a warning. A table created earlier in the same run holds no rows and no running code knows it yet: what is done to it is only checked for what would fail. What breaks the code still running is refused whatever the size, unless `deployment` is set to `maintenance`, where the application is down while the migrations run and those findings are notes.

## What each statement costs

Measured with `bench/measure.php` on a table of 10 million rows, while two other sessions keep writing and reading random rows: the longest write and the longest read during the statement are what it blocked. The servers ran on 2 vCPUs with the table in memory; a wider table with more indexes, on a busier server, takes longer. What matters is the gap between each statement and its safe form.

| PostgreSQL 16.15, 10,000,000 rows | Runs | Blocks writes | Blocks reads |
| --- | ---: | ---: | ---: |
| Index | 3.0 s | 2.9 s | no |
| Index, concurrently | 4.9 s | no | no |
| Unique constraint | 5.1 s | 5.1 s | 5.1 s |
| Unique index concurrently, then the constraint | 6.7 s | 0.2 s | no |
| Foreign key | 1.5 s | 1.5 s | no |
| Foreign key not valid, then validated | 1.4 s | no | no |
| Check constraint | 0.6 s | 0.6 s | 0.6 s |
| Check not valid, then validated | 0.5 s | no | no |
| NOT NULL | 0.9 s | 0.9 s | 0.9 s |
| NOT NULL, proven by a check first | 0.6 s | no | no |
| Column with a constant default | < 0.1 s | no | no |
| Column with a volatile default | 23.2 s | 22.6 s | 22.6 s |
| Column type, integer to bigint | 13.1 s | 12.3 s | 12.3 s |
| Every row updated in one statement | 39.8 s | 39.1 s | no |

| MySQL 8.0.46, 10,000,000 rows | Runs | Blocks writes | Blocks reads |
| --- | ---: | ---: | ---: |
| Index | 15.4 s | 0.1 s | no |
| Unique index | 28.9 s | 0.3 s | no |
| Fulltext index | 152.2 s | 152.2 s | 0.6 s |
| Foreign key, checks on | 78.2 s | 78.2 s | 0.6 s |
| Foreign key, checks off | 14.2 s | no | no |
| Check constraint | 30.4 s | 30.4 s | 1.0 s |
| NOT NULL | 19.2 s | 0.7 s | 0.7 s |
| Column, instant | < 0.1 s | no | no |
| Longer varchar, same length prefix | < 0.1 s | no | no |
| Longer varchar, longer length prefix | 30.6 s | 30.6 s | 0.5 s |
| Column type, int to bigint | 30.3 s | 30.3 s | 0.9 s |
| Every row updated in one statement | 173.4 s | 171.6 s | no |

MariaDB 10.11.14 behaves as MySQL on the same table: the index was built in 11.8 s with writes blocked for 0.1 s, while the FULLTEXT index blocked writes for 40.0 s and the foreign key, with checks on, for 125.3 s. `php bench/measure.php pgsql|mysql|mariadb [rows]` runs the same measurements on your server.

## When you know better

A statement you have checked is vouched for with `Schema::safetyAssured()`; the statements it runs are reported as assured instead of being refused, the rest of the migration is still checked:

```php
public function up(): void
{
    Schema::safetyAssured(fn () => Schema::table('orders', function (Blueprint $table) {
        $table->dropColumn('legacy_status');
    }));
}
```

A whole migration is vouched for with the `SafetyAssured` attribute and its reason, printed instead of the analysis. The migration is not run dry at all, which is also the way out for a migration whose side effects must not happen twice:

```php
use StrictMigrations\Attributes\SafetyAssured;

return new #[SafetyAssured('orders holds 300 rows in production')] class extends Migration
{
    // ...
};
```

A rule your team has decided against is turned off by its name in `disabled_rules`.

## In CI: `migrate:check`

`php artisan migrate:check` runs the analysis without applying anything, and exits with `1` when a migration would be refused, `2` when the check itself could not run.

```bash
php artisan migrate:check                      # the pending migrations, newer than check_after
php artisan migrate:check --path=database/migrations/2026_10_23_091500_add_customer_index_to_orders.php
php artisan migrate:check --all                # every migration, ran or not
php artisan migrate:check --sizes              # measure the tables instead of assuming them large
php artisan migrate:check --format=github      # annotations on the pull request
php artisan migrate:check --format=json        # for another tool
```

It needs a database of the production engine, empty or not: the grammar of the driver writes the SQL, and the version of the server decides the rules. A check on SQLite says nothing about PostgreSQL. Without `--sizes`, every existing table is assumed large; with it, the tables are measured in the database the command connects to, which only means something against production or a copy of it.

A pull request is best checked on the migrations it adds:

```yaml
services:
  postgres:
    image: postgres:17
    env:
      POSTGRES_PASSWORD: secret
    ports: ['5432:5432']

steps:
  - uses: actions/checkout@v5
    with:
      fetch-depth: 0
  # setup-php, composer install...
  - name: Check the new migrations
    run: |
      added=$(git diff --name-only --diff-filter=A origin/${{ github.base_ref }}... -- database/migrations)
      [ -z "$added" ] || php artisan migrate:check --format=github $(printf -- '--path=%s ' $added)
    env:
      DB_CONNECTION: pgsql
      DB_HOST: 127.0.0.1
      DB_DATABASE: postgres
      DB_USERNAME: postgres
      DB_PASSWORD: secret
```

On an empty database, a type change is judged as if it rewrote the table, since the current type is unknown; run the check on a database migrated to the base branch for exact verdicts.

## The lock timeout

Every migration runs with a bounded wait for its locks, 10 seconds by default: `lock_timeout` on PostgreSQL, `lock_wait_timeout` on MySQL and MariaDB, restored when the migration ends. A migration that times out is run again after a pause, three times by default, when that is safe: it ran in a transaction, on PostgreSQL, or none of its statements had been applied yet. Otherwise it fails with the message to check what was applied. A concurrent index build or drop runs without the timeout, since one that times out leaves an invalid index behind.

## Configuration

| Key | Default | |
| --- | --- | --- |
| `mode` | `env('STRICT_MIGRATIONS', 'block')` | `block`, `warn` or `off` |
| `disabled_in` | `['testing']` | environments where migrations run unchecked |
| `check_after` | the time of the installation | migrations with this timestamp or an earlier one are not judged; `null` judges them all |
| `check_down` | `false` | judge `down()` too, on rollbacks |
| `small_table_rows` | `10000` | at or below this many rows, a lock is a warning |
| `deployment` | `rolling` | `maintenance` when the application is down during migrations: what breaks running code becomes a note |
| `lock_timeout_seconds` | `10` | `0` leaves the session alone |
| `lock_timeout_retries`, `lock_timeout_retry_seconds` | `3`, `10` | |
| `connections` | `null` | the connections whose migrations are checked; `null` for all |
| `disabled_rules` | `[]` | rules turned off by name, such as `json` or `drop-index` |

## Limits

- **The migration runs twice:** once dry, once for real. Jobs, mail, notifications and HTTP calls are withheld during the dry run; anything else outside the database, such as files or the cache, happens twice. `#[SafetyAssured]` skips the dry run.
- **A dry run reads what is there.** A migration that creates a table and then asks `Schema::hasTable()` about it gets `false` in the dry run, since the table was not created.
- **Other connections stop the analysis.** A write through `DB::connection('other')` cannot be recorded: the analysis stops there with a warning, and the rest of the migration is not judged.
- **It refuses, it does not rewrite.** Fixing the migration is your call, recipe in hand.
- SQL Server migrations are not analyzed.

## How it works

The package replaces Laravel's migrator with a subclass that analyzes each migration just before running it. The analysis points the connection at a recording PDO for the length of the migration: reads go to the real database, so `Schema::hasColumn()` and the queries of the migration see what is there; transactions are acknowledged and do nothing; every other statement is recorded and never sent. The bus, queue, mail, notification and HTTP facades are faked meanwhile. Any statement that would still reach a database, through another connection or after a reconnection, stops the dry run before it is sent.

The recorded statements are tokenized for their dialect, split, and read into operations: an index, a type change, a dropped column. Each operation is judged against the server version, the table sizes and the current definition of the columns it changes, read from the database. The analysis of a migration costs one dry run and a bounded count per table it touches.

## Development

```bash
composer install
composer test      # Pest, on SQLite
composer analyse   # PHPStan (Larastan, level 8)
composer lint      # Pint
```

`STRICT_MIGRATIONS_TEST_DB` runs the suite on another engine: `pgsql`, `mysql` or `mariadb`, reached with `STRICT_MIGRATIONS_TEST_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME` and `_PASSWORD` (`127.0.0.1`, the engine's port, `strict`, `strict`, `secret` by default). Each rule is tested on each engine, and the guard, the lock timeout and `migrate:check` are tested end to end with Laravel's migrator. `php bench/measure.php pgsql|mysql|mariadb [rows]` measures what each risky statement blocks, as in the table above.

## License

MIT.

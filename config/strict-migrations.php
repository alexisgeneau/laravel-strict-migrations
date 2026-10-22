<?php

declare(strict_types=1);

return [

    // block refuses an unsafe migration before any of its statements runs, warn reports it and
    // runs it, off turns the guard and the lock timeout off.
    'mode' => env('STRICT_MIGRATIONS', 'block'),

    // Environments where migrations run unchecked: RefreshDatabase replays them for every test run.
    'disabled_in' => ['testing'],

    // Migrations whose name carries this timestamp or an earlier one are not judged: they ran in
    // production long ago. strict-migrations:install sets it to the time of the installation.
    'check_after' => env('STRICT_MIGRATIONS_CHECK_AFTER'),

    'check_down' => false,

    // Below this many rows, a statement that would block a large table is reported as a warning.
    'small_table_rows' => 10_000,

    // rolling: the previous release keeps serving while the migrations run, so dropping or renaming
    // what it uses is refused. maintenance: the application is down meanwhile, and those are notes.
    'deployment' => 'rolling',

    // How long a migration waits for a lock before giving up (0 leaves the session alone), and how
    // many more times it is run when nothing of it was applied.
    'lock_timeout_seconds' => 10,
    'lock_timeout_retries' => 3,
    'lock_timeout_retry_seconds' => 10,

    // The connections whose migrations are checked; null checks them all.
    'connections' => null,

    // Rules turned off by name, such as json or drop-index.
    'disabled_rules' => [],

];

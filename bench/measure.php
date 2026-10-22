<?php

declare(strict_types=1);

/*
 * Measures what the risky statements of a migration block on a large table, and for how
 * long: each statement runs in its own process while two others keep writing and reading
 * random rows, and the longest write and the longest read are what the statement blocked.
 *
 *     php bench/measure.php pgsql|mysql|mariadb [rows]
 *
 * The connection comes from BENCH_HOST, BENCH_PORT, BENCH_DATABASE, BENCH_USERNAME and
 * BENCH_PASSWORD. The tables bench_orders and bench_customers are dropped and refilled.
 */

const SCENARIOS = [
    'pgsql' => [
        ['Index', ['create index bench_orders_customer on bench_orders (customer_id)'], ['drop index bench_orders_customer']],
        ['Index, concurrently', ['create index concurrently bench_orders_customer on bench_orders (customer_id)'], ['drop index bench_orders_customer']],
        ['Unique constraint', ['alter table bench_orders add constraint bench_orders_email unique (email)'], ['alter table bench_orders drop constraint bench_orders_email']],
        ['Unique index concurrently, then the constraint', ['create unique index concurrently bench_orders_email on bench_orders (email)', 'alter table bench_orders add constraint bench_orders_email unique using index bench_orders_email'], ['alter table bench_orders drop constraint bench_orders_email']],
        ['Foreign key', ['alter table bench_orders add constraint bench_orders_customer foreign key (customer_id) references bench_customers (id)'], ['alter table bench_orders drop constraint bench_orders_customer']],
        ['Foreign key not valid, then validated', ['alter table bench_orders add constraint bench_orders_customer foreign key (customer_id) references bench_customers (id) not valid', 'alter table bench_orders validate constraint bench_orders_customer'], ['alter table bench_orders drop constraint bench_orders_customer']],
        ['Check constraint', ['alter table bench_orders add constraint bench_orders_quantity check (quantity > 0)'], ['alter table bench_orders drop constraint bench_orders_quantity']],
        ['Check not valid, then validated', ['alter table bench_orders add constraint bench_orders_quantity check (quantity > 0) not valid', 'alter table bench_orders validate constraint bench_orders_quantity'], ['alter table bench_orders drop constraint bench_orders_quantity']],
        ['NOT NULL', ['alter table bench_orders alter column email set not null'], ['alter table bench_orders alter column email drop not null']],
        ['NOT NULL, proven by a check first', ['alter table bench_orders add constraint bench_orders_email check (email is not null) not valid', 'alter table bench_orders validate constraint bench_orders_email', 'alter table bench_orders alter column email set not null', 'alter table bench_orders drop constraint bench_orders_email'], ['alter table bench_orders alter column email drop not null']],
        ['Column with a constant default', ['alter table bench_orders add column archived boolean not null default false'], ['alter table bench_orders drop column archived']],
        ['Column with a volatile default', ['alter table bench_orders add column public_id uuid default gen_random_uuid()'], ['alter table bench_orders drop column public_id']],
        ['Column type, integer to bigint', ['alter table bench_orders alter column quantity type bigint'], []],
        ['Every row updated in one statement', ["update bench_orders set status = 'archived'"], []],
    ],
    'mysql' => [
        ['Index', ['alter table bench_orders add index bench_orders_customer (customer_id)'], ['alter table bench_orders drop index bench_orders_customer']],
        ['Unique index', ['alter table bench_orders add unique bench_orders_email (email)'], ['alter table bench_orders drop index bench_orders_email']],
        ['Fulltext index', ['alter table bench_orders add fulltext bench_orders_email_text (email)'], ['alter table bench_orders drop index bench_orders_email_text']],
        ['Foreign key, checks on', ['alter table bench_orders add constraint bench_orders_customer foreign key (customer_id) references bench_customers (id)'], ['alter table bench_orders drop foreign key bench_orders_customer', 'alter table bench_orders drop index bench_orders_customer']],
        ['Foreign key, checks off', ['set foreign_key_checks = 0', 'alter table bench_orders add constraint bench_orders_customer foreign key (customer_id) references bench_customers (id)', 'set foreign_key_checks = 1'], ['alter table bench_orders drop foreign key bench_orders_customer', 'alter table bench_orders drop index bench_orders_customer']],
        ['Check constraint', ['alter table bench_orders add constraint bench_orders_quantity check (quantity > 0)'], ['alter table bench_orders drop constraint bench_orders_quantity']],
        ['NOT NULL', ['alter table bench_orders modify email varchar(255) not null'], ['alter table bench_orders modify email varchar(255) null']],
        ['Column, instant', ['alter table bench_orders add column archived tinyint(1) not null default 0'], ['alter table bench_orders drop column archived']],
        ['Longer varchar, same length prefix', ['alter table bench_orders modify status varchar(60) not null'], []],
        ['Longer varchar, longer length prefix', ['alter table bench_orders modify status varchar(100) not null'], []],
        ['Column type, int to bigint', ['alter table bench_orders modify quantity bigint not null'], []],
        ['Every row updated in one statement', ["update bench_orders set status = 'archived'"], []],
    ],
];

$mode = $argv[1] ?? '';

match ($mode) {
    '--statements' => runStatements($argv[2], json_decode(base64_decode($argv[3]), true)),
    '--probe' => probe($argv[2], $argv[3], (int) $argv[4], $argv[5]),
    'pgsql', 'mysql', 'mariadb' => measure($mode, (int) ($argv[2] ?? 10_000_000)),
    default => fwrite(STDERR, "Usage: php bench/measure.php pgsql|mysql|mariadb [rows]\n"),
};

function connect(string $engine): PDO
{
    $host = getenv('BENCH_HOST') ?: '127.0.0.1';
    $port = getenv('BENCH_PORT') ?: ($engine === 'pgsql' ? '5432' : '3306');
    $database = getenv('BENCH_DATABASE') ?: 'strict';
    $dsn = $engine === 'pgsql' ? "pgsql:host={$host};port={$port};dbname={$database}" : "mysql:host={$host};port={$port};dbname={$database}";

    $pdo = new PDO($dsn, getenv('BENCH_USERNAME') ?: 'strict', getenv('BENCH_PASSWORD') ?: 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if ($engine === 'pgsql') {
        $pdo->exec('set lock_timeout = 0');
        $pdo->exec('set statement_timeout = 0');
    } else {
        $pdo->exec('set session lock_wait_timeout = 31536000');
        $pdo->exec('set session innodb_lock_wait_timeout = 3600');
    }

    return $pdo;
}

function measure(string $engine, int $rows): void
{
    $pdo = connect($engine);
    $version = (string) $pdo->query($engine === 'pgsql' ? 'show server_version' : 'select version()')->fetchColumn();

    fwrite(STDERR, "Filling bench_orders with {$rows} rows on {$engine} {$version}...\n");
    fill($pdo, $engine, $rows);

    $results = [];

    foreach (SCENARIOS[$engine === 'pgsql' ? 'pgsql' : 'mysql'] as [$label, $statements, $cleanup]) {
        fwrite(STDERR, "  {$label}... ");
        $result = timeBlocking($engine, $rows, $statements);

        foreach ($cleanup as $statement) {
            $pdo->exec($statement);
        }

        fwrite(STDERR, sprintf("%.1f s, writes blocked %.1f s, reads blocked %.1f s\n", ...$result));
        $results[] = [$label, ...$result];
    }

    $name = ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'][$engine];
    $number = preg_match('/^\d+(?:\.\d+)*/', $version, $match) ? $match[0] : $version;

    printf("| %s %s, %s rows | Runs | Blocks writes | Blocks reads |\n", $name, $number, number_format($rows));
    echo "| --- | ---: | ---: | ---: |\n";

    foreach ($results as [$label, $seconds, $writes, $reads]) {
        printf("| %s | %s | %s | %s |\n", $label, duration($seconds), blocked($writes), blocked($reads));
    }
}

function fill(PDO $pdo, string $engine, int $rows): void
{
    $pdo->exec('drop table if exists bench_orders');
    $pdo->exec('drop table if exists bench_customers');

    if ($engine === 'pgsql') {
        $pdo->exec('create table bench_customers (id bigint primary key)');
        $pdo->exec('insert into bench_customers select generate_series(1, 100000)');
        $pdo->exec('create table bench_orders (id bigint generated always as identity primary key, customer_id bigint not null, quantity integer not null, email varchar(255) null, status varchar(20) not null, notes text null, created_at timestamp(0) not null)');
        $pdo->exec("insert into bench_orders (customer_id, quantity, email, status, created_at) select i % 100000 + 1, i % 7 + 1, 'customer' || i || '@example.com', 'new', now() from generate_series(1, {$rows}) as i");
        $pdo->exec('vacuum analyze bench_orders');

        return;
    }

    $pdo->exec('create table bench_customers (id bigint unsigned primary key) engine = InnoDB');
    $pdo->exec('create table bench_orders (id bigint unsigned auto_increment primary key, customer_id bigint unsigned not null, quantity int not null, email varchar(255) null, status varchar(20) not null, notes text null, created_at timestamp not null) engine = InnoDB default charset utf8mb4 collate utf8mb4_unicode_ci');
    $pdo->exec('drop table if exists bench_digits');
    $pdo->exec('create table bench_digits (n int not null)');
    $pdo->exec('insert into bench_digits values (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');

    $pdo->exec('insert into bench_customers (id) select n from ('.sequence(100_000).') seq');
    $pdo->exec("insert into bench_orders (customer_id, quantity, email, status, created_at) select n % 100000 + 1, n % 7 + 1, concat('customer', n, '@example.com'), 'new', now() from (".sequence($rows).') seq order by n');
    $pdo->exec('drop table bench_digits');
    $pdo->query('analyze table bench_orders')->fetchAll();
}

/** The numbers 1 to $count, as a MySQL query over a table of the ten digits. */
function sequence(int $count): string
{
    $places = range(0, max(0, (int) ceil(log10($count)) - 1));
    $number = implode(' + ', array_map(fn (int $place): string => "d{$place}.n * ".(10 ** $place), $places));
    $digits = implode(' cross join ', array_map(fn (int $place): string => "bench_digits d{$place}", $places));

    return "select n from (select {$number} + 1 as n from {$digits}) numbers where n <= {$count}";
}

/**
 * Runs the statements in a process of their own while two others keep writing and reading,
 * and returns how long the statements ran and the longest write and read meanwhile.
 *
 * @param  list<string>  $statements
 * @return array{float, float, float}
 */
function timeBlocking(string $engine, int $rows, array $statements): array
{
    $stop = tempnam(sys_get_temp_dir(), 'bench');
    unlink($stop);

    $writer = spawn(['--probe', $engine, 'write', (string) $rows, $stop]);
    $reader = spawn(['--probe', $engine, 'read', (string) $rows, $stop]);

    foreach ([$writer, $reader] as [, $pipes]) {
        fgets($pipes[1]);
    }

    [$process, $pipes] = spawn(['--statements', $engine, base64_encode(json_encode($statements))]);
    $seconds = (float) stream_get_contents($pipes[1]);
    proc_close($process);

    touch($stop);
    $waits = [];

    foreach ([$writer, $reader] as [$prober, $output]) {
        $waits[] = (float) stream_get_contents($output[1]);
        proc_close($prober);
    }

    unlink($stop);

    return [$seconds, ...$waits];
}

/**
 * @param  list<string>  $arguments
 * @return array{resource, array<int, resource>}
 */
function spawn(array $arguments): array
{
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);

    if ($process === false) {
        throw new RuntimeException('Could not start '.implode(' ', $arguments));
    }

    return [$process, $pipes];
}

/** @param list<string> $statements */
function runStatements(string $engine, array $statements): void
{
    $pdo = connect($engine);
    $start = hrtime(true);

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    echo (hrtime(true) - $start) / 1e9;
}

/** Writes or reads random rows until the stop file appears, then prints the longest wait. */
function probe(string $engine, string $kind, int $rows, string $stop): void
{
    $pdo = connect($engine);
    $statement = $pdo->prepare($kind === 'write'
        ? "update bench_orders set notes = 'probe' where id = ?"
        : 'select status from bench_orders where id = ?');
    $run = function () use ($statement, $kind, $rows): float {
        $start = hrtime(true);
        $statement->execute([random_int(1, $rows)]);

        if ($kind === 'read') {
            $statement->fetchAll();
        }

        return (hrtime(true) - $start) / 1e9;
    };

    $run();
    echo "ready\n";
    fflush(STDOUT);
    $longest = 0.0;

    while (! file_exists($stop)) {
        $longest = max($longest, $run());
        usleep(20_000);
    }

    echo $longest;
}

function duration(float $seconds): string
{
    return $seconds < 0.1 ? '< 0.1 s' : sprintf('%.1f s', $seconds);
}

function blocked(float $seconds): string
{
    return $seconds < 0.1 ? 'no' : sprintf('%.1f s', $seconds);
}

<?php

declare(strict_types=1);

use StrictMigrations\Operations\AddCheck;
use StrictMigrations\Operations\AddColumn;
use StrictMigrations\Operations\AddForeignKey;
use StrictMigrations\Operations\AddIndex;
use StrictMigrations\Operations\AddPrimaryKey;
use StrictMigrations\Operations\AddUniqueConstraint;
use StrictMigrations\Operations\AlterTable;
use StrictMigrations\Operations\ChangeColumnType;
use StrictMigrations\Operations\CreateTable;
use StrictMigrations\Operations\DataChange;
use StrictMigrations\Operations\DropColumn;
use StrictMigrations\Operations\DropIndex;
use StrictMigrations\Operations\DropPrimaryKey;
use StrictMigrations\Operations\DropTable;
use StrictMigrations\Operations\Harmless;
use StrictMigrations\Operations\LockTable;
use StrictMigrations\Operations\Maintenance;
use StrictMigrations\Operations\ModifyColumn;
use StrictMigrations\Operations\RebuildTable;
use StrictMigrations\Operations\RenameColumn;
use StrictMigrations\Operations\RenameTable;
use StrictMigrations\Operations\SessionSetting;
use StrictMigrations\Operations\SetNotNull;
use StrictMigrations\Operations\TruncateTable;
use StrictMigrations\Operations\Unread;
use StrictMigrations\Sql\Classifier;
use StrictMigrations\Sql\Dialect;

/**
 * @param  list<array{class-string, array<string, mixed>}>  $expected  Each operation's class and the properties it must have.
 */
function expectOperations(string $sql, Dialect $dialect, array $expected): void
{
    $operations = (new Classifier)->classify($sql, $dialect);

    expect($operations)->toHaveCount(count($expected));

    foreach ($expected as $index => [$class, $properties]) {
        expect($operations[$index])->toBeInstanceOf($class)->toHaveProperties($properties);
    }
}

it('reads the statements of the PostgreSQL grammar', function (string $sql, array $expected) {
    expectOperations($sql, Dialect::Postgres, $expected);
})->with([
    'create index' => ['create index "orders_customer_id_index" on "orders" ("customer_id")', [[AddIndex::class, ['table' => 'orders', 'name' => 'orders_customer_id_index', 'columns' => ['customer_id'], 'concurrently' => false]]]],
    'create index concurrently' => ['create unique index concurrently if not exists "orders_email_unique" on "public"."orders" using btree ("email", lower("name"))', [[AddIndex::class, ['table' => 'public.orders', 'unique' => true, 'concurrently' => true, 'method' => 'btree', 'columns' => ['email', 'lower("name")']]]]],
    'unique constraint' => ['alter table "orders" add constraint "orders_email_unique" unique ("email")', [[AddUniqueConstraint::class, ['name' => 'orders_email_unique', 'columns' => ['email'], 'usingIndex' => false]]]],
    'unique using index' => ['alter table "orders" add constraint "orders_email_unique" unique using index "orders_email_unique"', [[AddUniqueConstraint::class, ['usingIndex' => true]]]],
    'add columns' => ['alter table "orders" add column "currency" varchar(3) null, add column "paid" boolean not null default \'0\'', [
        [AddColumn::class, ['column' => 'currency']],
        [AddColumn::class, ['column' => 'paid']],
    ]],
    'column type' => ['alter table "orders" alter column "total" type numeric(12, 2), alter column "total" drop not null, alter column "total" set default \'0\'', [
        [ChangeColumnType::class, ['column' => 'total', 'type' => 'numeric(12,2)', 'using' => false]],
        [AlterTable::class, ['table' => 'orders']],
        [AlterTable::class, ['table' => 'orders']],
    ]],
    'column type using' => ['alter table orders alter column status type integer using status::integer', [[ChangeColumnType::class, ['type' => 'integer', 'using' => true]]]],
    'set not null' => ['alter table "orders" alter column "email" set not null', [[SetNotNull::class, ['table' => 'orders', 'column' => 'email']]]],
    'foreign key' => ['alter table "orders" add constraint "orders_customer_id_foreign" foreign key ("customer_id") references "customers" ("id") on delete cascade not valid', [[AddForeignKey::class, ['name' => 'orders_customer_id_foreign', 'columns' => ['customer_id'], 'references' => 'customers', 'referencedColumns' => ['id'], 'notValid' => true]]]],
    'check' => ['alter table orders add constraint orders_total check (total >= 0)', [[AddCheck::class, ['name' => 'orders_total', 'expression' => 'total >= 0', 'notValid' => false]]]],
    'primary key' => ['alter table "events" add primary key ("uuid")', [[AddPrimaryKey::class, ['columns' => ['uuid']]]]],
    'drop column' => ['alter table "orders" drop column "notes", drop column if exists "total"', [
        [DropColumn::class, ['column' => 'notes']],
        [DropColumn::class, ['column' => 'total']],
    ]],
    'drop constraint' => ['alter table "orders" drop constraint "orders_email_unique"', [[AlterTable::class, ['table' => 'orders']]]],
    'rename column' => ['alter table "orders" rename column "notes" to "comments"', [[RenameColumn::class, ['from' => 'notes', 'to' => 'comments']]]],
    'rename table' => ['alter table "orders" rename to "purchases"', [[RenameTable::class, ['from' => 'orders', 'to' => 'purchases']]]],
    'set schema' => ['alter table public.orders set schema archive', [[RenameTable::class, ['from' => 'public.orders', 'to' => 'archive.orders']]]],
    'drop index' => ['drop index concurrently if exists "orders_email_index"', [[DropIndex::class, ['name' => 'orders_email_index', 'concurrently' => true]]]],
    'drop tables' => ['drop table if exists "orders", "carts" cascade', [
        [DropTable::class, ['table' => 'orders', 'ifExists' => true]],
        [DropTable::class, ['table' => 'carts', 'ifExists' => true]],
    ]],
    'truncate' => ['truncate table "orders" restart identity cascade', [[TruncateTable::class, ['table' => 'orders']]]],
    'create table' => ['create table "invoices" ("id" bigserial not null primary key, "lines" json not null, constraint "invoices_number" unique ("id"))', [[CreateTable::class, ['table' => 'invoices', 'temporary' => false, 'jsonColumns' => ['lines']]]]],
    'create temporary table' => ['create temporary table "scratch" ("id" integer)', [[CreateTable::class, ['temporary' => true]]]],
    'update with a CTE' => ['with stale as (select id from orders) update orders set status = ? where id in (select id from stale)', [[DataChange::class, ['verb' => 'update', 'table' => 'orders', 'everyRow' => false]]]],
    'delete everything' => ['delete from "orders"', [[DataChange::class, ['verb' => 'delete', 'everyRow' => true]]]],
    'lock' => ['lock table orders, carts in share mode', [[LockTable::class, ['table' => 'orders']], [LockTable::class, ['table' => 'carts']]]],
    'vacuum full' => ['vacuum (full, analyze) orders', [[RebuildTable::class, ['table' => 'orders', 'what' => 'vacuum full']]]],
    'reindex' => ['reindex table concurrently orders', [[Maintenance::class, ['command' => 'reindex', 'target' => 'orders', 'concurrently' => true]]]],
    'refresh' => ['refresh materialized view concurrently order_totals', [[Maintenance::class, ['command' => 'refresh', 'target' => 'order_totals', 'concurrently' => true]]]],
    'set logged' => ['alter table orders set unlogged', [[RebuildTable::class, ['what' => 'set unlogged']]]],
    'objects' => ["create or replace view recent as select 1; comment on table orders is 'x'", [[Harmless::class, []]]],
    'unknown' => ['alter table orders attach partition orders_2026 for values in (2026)', [[Unread::class, []]]],
    'not a statement' => ['(select 1)', [[Unread::class, []]]],
]);

it('reads the statements of the MySQL grammar', function (string $sql, array $expected) {
    expectOperations($sql, Dialect::MySql, $expected);
})->with([
    'add index' => ['alter table `orders` add index `orders_customer_id_index`(`customer_id`)', [[AddIndex::class, ['name' => 'orders_customer_id_index', 'columns' => ['customer_id'], 'method' => null]]]],
    'add fulltext' => ['alter table `orders` add fulltext `orders_notes_fulltext`(`notes`)', [[AddIndex::class, ['method' => 'fulltext']]]],
    'add unique' => ['alter table `orders` add unique `orders_email_unique`(`email`)', [[AddIndex::class, ['name' => 'orders_email_unique', 'unique' => true]]]],
    'algorithm and lock' => ['alter table `orders` add index `orders_email_index`(`email`), algorithm=inplace, lock=none', [[AddIndex::class, ['options' => new StrictMigrations\Operations\AlterOptions('inplace', 'none')]]]],
    'add column' => ['alter table `orders` add `currency` varchar(3) null after `total`', [[AddColumn::class, ['column' => 'currency']]]],
    'modify' => ['alter table `orders` modify `status` varchar(30) not null default \'new\'', [[ModifyColumn::class, ['column' => 'status', 'renameTo' => null]]]],
    'change' => ['alter table `orders` change `notes` `comments` text null', [[ModifyColumn::class, ['column' => 'notes', 'renameTo' => 'comments']]]],
    'drop columns' => ['alter table `orders` drop `notes`, drop `total`', [[DropColumn::class, ['column' => 'notes']], [DropColumn::class, ['column' => 'total']]]],
    'drop index' => ['alter table `orders` drop index `orders_email_index`', [[AlterTable::class, []]]],
    'drop foreign key' => ['alter table `orders` drop foreign key `orders_customer_id_foreign`', [[AlterTable::class, []]]],
    'drop primary key' => ['alter table `events` drop primary key', [[DropPrimaryKey::class, ['table' => 'events']]]],
    'replace the primary key' => ['alter table `events` drop primary key, add primary key (`uuid`)', [[AlterTable::class, []], [AddPrimaryKey::class, ['columns' => ['uuid']]]]],
    'rename tables' => ['rename table `orders` to `purchases`, `carts` to `baskets`', [[RenameTable::class, ['from' => 'orders', 'to' => 'purchases']], [RenameTable::class, ['from' => 'carts', 'to' => 'baskets']]]],
    'create index' => ['create unique index `orders_email` on `orders` (`email`) algorithm = inplace lock = none', [[AddIndex::class, ['unique' => true, 'options' => new StrictMigrations\Operations\AlterOptions('inplace', 'none')]]]],
    'convert' => ['alter table `orders` convert to character set utf8mb4 collate utf8mb4_unicode_ci', [[RebuildTable::class, ['what' => 'converting its character set']]]],
    'engine' => ['alter table `orders` engine = InnoDB', [[AlterTable::class, []]]],
    'foreign key checks' => ['SET FOREIGN_KEY_CHECKS=0', [[SessionSetting::class, ['name' => 'foreign_key_checks', 'value' => '0']]]],
    'session variables' => ['set @@session.lock_wait_timeout = 10', [[SessionSetting::class, ['name' => 'lock_wait_timeout', 'value' => '10']]]],
    'replace into' => ['replace into `settings` (`key`, `value`) values (?, ?)', [[DataChange::class, ['verb' => 'insert', 'table' => 'settings']]]],
    'lock tables' => ['lock tables `orders` write', [[LockTable::class, ['table' => 'orders']]]],
]);

it('reads the statements of the SQLite grammar', function (string $sql, array $expected) {
    expectOperations($sql, Dialect::Sqlite, $expected);
})->with([
    'add column' => ['alter table "orders" add column "currency" varchar', [[AddColumn::class, ['column' => 'currency']]]],
    'rebuild' => ['create table "__temp__orders" ("id" integer primary key autoincrement not null)', [[CreateTable::class, ['table' => '__temp__orders']]]],
    'pragma' => ['pragma foreign_keys = off', [[Harmless::class, []]]],
    'bracketed names' => ['drop table [orders]', [[DropTable::class, ['table' => 'orders']]]],
]);

<?php

declare(strict_types=1);

use StrictMigrations\Analysis\Run;
use StrictMigrations\Findings\TableSize;

it('describes the size of a table against the threshold', function (?int $rows, bool $small, string $description) {
    $size = new TableSize('orders', $rows, 10_000);

    expect($size->small())->toBe($small)
        ->and($size->describe())->toBe($description);
})->with([
    [null, false, 'orders: size not measured, assumed large'],
    [1, true, 'orders: 1 row, under the 10,000-row threshold'],
    [10_000, true, 'orders: 10,000 rows, under the 10,000-row threshold'],
    [10_001, false, 'orders: more than 10,000 rows'],
]);

it('follows the tables a run creates, renames and drops', function () {
    $run = new Run;
    $run->create('public.invoices');
    $run->rename('invoices', 'bills');
    $run->rename('orders', 'purchases');

    expect($run->created('bills'))->toBeTrue()
        ->and($run->created('invoices'))->toBeFalse()
        ->and($run->created('purchases'))->toBeFalse();

    $run->forget('bills');

    expect($run->created('bills'))->toBeFalse();
});

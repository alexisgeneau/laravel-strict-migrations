<?php

declare(strict_types=1);

namespace StrictMigrations\Findings;

final class Findings
{
    /** @return list<Finding> The findings given, without the nulls of the risks that did not apply. */
    public static function of(?Finding ...$findings): array
    {
        return array_values(array_filter($findings));
    }
}

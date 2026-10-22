<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

/** PHP literals for the code written in recipes. */
final class Php
{
    public static function string(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    /** @param list<string> $values */
    public static function list(array $values): string
    {
        return count($values) === 1 ? self::string($values[0]) : '['.implode(', ', array_map(self::string(...), $values)).']';
    }

    /** A statement run through DB::statement(), indented as a recipe line. */
    public static function statement(string $sql): string
    {
        return '    DB::statement('.self::string($sql).');';
    }
}

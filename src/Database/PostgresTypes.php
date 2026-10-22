<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

/** When changing a column's type makes PostgreSQL rewrite the table, and when it does not. */
final class PostgresTypes
{
    private const NAMES = [
        'character varying' => 'varchar',
        'character' => 'char',
        'bpchar' => 'char',
        'int' => 'integer',
        'int4' => 'integer',
        'serial' => 'integer',
        'serial4' => 'integer',
        'int8' => 'bigint',
        'bigserial' => 'bigint',
        'serial8' => 'bigint',
        'int2' => 'smallint',
        'smallserial' => 'smallint',
        'serial2' => 'smallint',
        'decimal' => 'numeric',
        'float8' => 'double precision',
        'float4' => 'real',
        'bool' => 'boolean',
        'bit varying' => 'varbit',
        'timestamp without time zone' => 'timestamp',
        'timestamp with time zone' => 'timestamptz',
        'time without time zone' => 'time',
        'time with time zone' => 'timetz',
    ];

    /** Types whose length or precision may grow without a rewrite, with the precision they have when unbounded. */
    private const GROWABLE = ['varchar' => PHP_INT_MAX, 'varbit' => PHP_INT_MAX, 'timestamp' => 6, 'timestamptz' => 6, 'time' => 6, 'timetz' => 6, 'interval' => 6];

    /** "character varying(255)" and "varchar(255)" both read as varchar(255), "timestamp(0) without time zone" as timestamp(0). */
    public static function canonical(string $type): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));
        $array = str_ends_with($type, '[]') ? '[]' : '';
        $modifier = preg_match('/\(([^)]*)\)/', $type, $match) ? '('.str_replace(' ', '', $match[1]).')' : '';
        $name = trim((string) preg_replace('/\([^)]*\)|\[\]/', '', $type));
        $name = (string) preg_replace('/\s+/', ' ', $name);

        return (self::NAMES[$name] ?? $name).$modifier.$array;
    }

    public static function rewrites(string $from, string $to, bool $utcSession): bool
    {
        [$from, $to] = [self::canonical($from), self::canonical($to)];

        if ($from === $to) {
            return false;
        }

        [$fromName, $fromModifier] = self::split($from);
        [$toName, $toModifier] = self::split($to);

        if (($fromName === 'varchar' && $toName === 'text') || ($fromName === 'text' && $to === 'varchar')) {
            return false;
        }

        if (in_array($fromName.'>'.$toName, ['timestamp>timestamptz', 'timestamptz>timestamp'], true)) {
            return ! $utcSession || self::narrows($fromModifier, $toModifier, 6);
        }

        if ($fromName !== $toName) {
            return true;
        }

        if ($fromName === 'numeric') {
            return self::numericNarrows($fromModifier, $toModifier);
        }

        return ! array_key_exists($fromName, self::GROWABLE) || self::narrows($fromModifier, $toModifier, self::GROWABLE[$fromName]);
    }

    /** @return array{string, string|null} The type's name and what its parentheses hold. */
    private static function split(string $type): array
    {
        return preg_match('/^([^(\[]+)(?:\(([^)]*)\))?/', $type, $match)
            ? [trim($match[1]), $match[2] ?? null]
            : [$type, null];
    }

    private static function narrows(?string $from, ?string $to, int $unbounded): bool
    {
        return (int) ($to ?? $unbounded) < (int) ($from ?? $unbounded);
    }

    /** numeric(p, s) may only grow its precision, keeping its scale; unbounded numeric takes anything. */
    private static function numericNarrows(?string $from, ?string $to): bool
    {
        if ($to === null) {
            return false;
        }

        if ($from === null) {
            return true;
        }

        [$fromPrecision, $fromScale] = array_map('intval', explode(',', $from.',0'));
        [$toPrecision, $toScale] = array_map('intval', explode(',', $to.',0'));

        return $toScale !== $fromScale || $toPrecision < $fromPrecision;
    }
}

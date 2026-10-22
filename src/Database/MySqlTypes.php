<?php

declare(strict_types=1);

namespace StrictMigrations\Database;

use StrictMigrations\Operations\ColumnDefinition;

/**
 * How MySQL and MariaDB carry out a column change, as their own ALGORITHM checks decide it:
 * a default or an appended ENUM value is instant, a longer VARCHAR within the same length
 * prefix or a nullability change is rebuilt in place, a new collation depends on the engine
 * and on whether the column is indexed, and anything else copies the table.
 */
final class MySqlTypes
{
    public const INSTANT = 'instant';

    public const INPLACE = 'inplace';

    public const COPY = 'copy';

    private const BYTES_PER_CHARACTER = ['utf8mb4' => 4, 'utf8mb3' => 3, 'latin1' => 1, 'ascii' => 1, 'binary' => 1, 'ucs2' => 2, 'utf16' => 4, 'utf16le' => 4, 'utf32' => 4];

    public static function algorithm(ColumnState $current, ColumnDefinition $change, Engine $engine): string
    {
        $charset = self::charset($current->collation);
        $newCharset = self::charset($change->charset ?? $change->collation);

        if ($charset !== null && $newCharset !== null && $newCharset !== $charset) {
            return self::COPY;
        }

        $nullability = $change->nullable === null ? $current->nullable === false : $change->nullable !== $current->nullable;
        $rebuild = $nullability || $change->positioned ? self::INPLACE : self::INSTANT;

        if ($change->collation !== null && strtolower($change->collation) !== strtolower((string) $current->collation)) {
            $rebuild = self::slowest($rebuild, match ($engine) {
                Engine::MariaDb => $current->indexed ? self::INPLACE : self::INSTANT,
                default => $current->indexed ? self::COPY : self::INPLACE,
            });
        }

        $from = self::canonical($current->type);
        $to = self::canonical($change->type);

        if ($from === $to) {
            return $rebuild;
        }

        if (preg_match('/^(varchar|varbinary)\((\d+)\)$/', $from, $old) && preg_match('/^(varchar|varbinary)\((\d+)\)$/', $to, $new) && $old[1] === $new[1]) {
            $bytes = $old[1] === 'varbinary' ? 1 : self::bytesPerCharacter($current->collation);
            $sameLengthPrefix = ((int) $old[2] * $bytes > 255) === ((int) $new[2] * $bytes > 255);

            return (int) $new[2] >= (int) $old[2] && $sameLengthPrefix ? self::slowest($rebuild, self::INPLACE) : self::COPY;
        }

        if (preg_match('/^(enum|set)\((.*)\)$/', $from, $old) && preg_match('/^(enum|set)\((.*)\)$/', $to, $new) && $old[1] === $new[1]) {
            return str_starts_with($new[2], $old[2].',') && self::storage($old[1], $old[2]) === self::storage($new[1], $new[2]) ? $rebuild : self::COPY;
        }

        return self::COPY;
    }

    /** "int(11)" and "integer" both read as int, "decimal(8, 2)" as decimal(8,2), "boolean" as tinyint(1). */
    public static function canonical(string $type): string
    {
        $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));
        $type = (string) preg_replace('/\s*([(),])\s*/', '$1', $type);
        $type = (string) preg_replace('/^(int|integer|bigint|smallint|mediumint)\(\d+\)/', '$1', $type);

        return match (true) {
            str_starts_with($type, 'integer') => 'int'.substr($type, 7),
            in_array($type, ['bool', 'boolean'], true) => 'tinyint(1)',
            str_starts_with($type, 'numeric') => 'decimal'.substr($type, 7),
            str_starts_with($type, 'double precision') => 'double'.substr($type, 16),
            default => $type,
        };
    }

    /** The bytes a value takes: members appended in place must not make it grow. */
    private static function storage(string $type, string $members): int
    {
        $count = (int) preg_match_all("/'(?:[^']|'')*'/", $members);

        if ($type === 'enum') {
            return $count <= 255 ? 1 : 2;
        }

        return $count > 32 ? 8 : intdiv($count + 7, 8);
    }

    private static function slowest(string ...$algorithms): string
    {
        foreach ([self::COPY, self::INPLACE] as $algorithm) {
            if (in_array($algorithm, $algorithms, true)) {
                return $algorithm;
            }
        }

        return self::INSTANT;
    }

    /** The character set a collation or a charset name belongs to: utf8mb4_unicode_ci and utf8mb4 both read as utf8mb4. */
    private static function charset(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $charset = explode('_', strtolower($name))[0];

        return $charset === 'utf8' ? 'utf8mb3' : $charset;
    }

    private static function bytesPerCharacter(?string $collation): int
    {
        return self::BYTES_PER_CHARACTER[self::charset($collation) ?? ''] ?? 4;
    }
}

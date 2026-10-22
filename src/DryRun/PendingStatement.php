<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use ArrayIterator;
use Iterator;
use PDO;
use PDOStatement;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Tokens;

/**
 * A statement handed to the connection during a dry run instead of a real one: executing it
 * records it, and it returns no rows, except the single row an INSERT ... RETURNING promises,
 * so that insertGetId() keeps working.
 */
final class PendingStatement extends PDOStatement
{
    /** @var array<int|string, mixed> */
    private array $bindings = [];

    private int $mode = PDO::FETCH_OBJ;

    private bool $fetched = false;

    public function __construct(
        private readonly string $sql,
        private readonly Dialect $dialect,
        private readonly ?Recording $recording = null,
    ) {}

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;

        return true;
    }

    public function bindParam(int|string $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $this->bindings[$param] = $var;

        return true;
    }

    /** @param array<int|string, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $bindings = $params ?? $this->bindings;
        ksort($bindings);
        $positional = array_filter(array_keys($bindings), is_string(...)) === [];

        $this->recording?->record($this->sql, $positional ? array_values($bindings) : $bindings);

        return true;
    }

    public function setFetchMode(int $mode, mixed ...$args): true
    {
        $this->mode = $mode;

        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $row = $this->fetch($mode);

        return $row === false ? [] : [$row];
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $columns = $this->returning();

        if ($columns === [] || $this->fetched) {
            return false;
        }

        $this->fetched = true;
        $row = array_fill_keys($columns, null);

        return match ($mode === PDO::FETCH_DEFAULT ? $this->mode : $mode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => [...$row, ...array_values($row)],
            default => (object) $row,
        };
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }

    public function rowCount(): int
    {
        return 0;
    }

    public function columnCount(): int
    {
        return count($this->returning());
    }

    public function nextRowset(): bool
    {
        return false;
    }

    public function closeCursor(): bool
    {
        return true;
    }

    public function errorCode(): string
    {
        return '00000';
    }

    /** @return array{string, null, null} */
    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->fetchAll());
    }

    /** @return list<string> The columns of the RETURNING clause, if the statement has one. */
    private function returning(): array
    {
        $tokens = Tokens::of($this->sql, $this->dialect);
        $tokens->until(fn ($token): bool => $token->is('returning'));

        if (! $tokens->accept('returning')) {
            return [];
        }

        return array_values(array_filter(array_map(fn (Tokens $column): ?string => $column->name(), $tokens->split())));
    }
}

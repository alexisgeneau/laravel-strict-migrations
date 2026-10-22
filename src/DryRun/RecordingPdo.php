<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use PDO;
use PDOStatement;
use StrictMigrations\Sql\Dialect;
use StrictMigrations\Sql\Intent;

/**
 * The PDO a connection talks to during a dry run: reads go to the real database, so that the
 * migration sees the schema as it stands; transaction control is acknowledged without effect;
 * every other statement is recorded and never sent.
 */
final class RecordingPdo extends PDO
{
    private int $transactions = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Recording $recording,
        private readonly Dialect $dialect,
    ) {}

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return match (Intent::of($query, $this->dialect)) {
            Intent::Read => $this->pdo->prepare($query, $options),
            Intent::Transaction => new PendingStatement($query, $this->dialect),
            Intent::Write => new PendingStatement($query, $this->dialect, $this->recording),
        };
    }

    public function exec(string $statement): int|false
    {
        $intent = Intent::of($statement, $this->dialect);

        if ($intent === Intent::Read) {
            return $this->pdo->exec($statement);
        }

        if ($intent === Intent::Write) {
            $this->recording->record($statement);
        }

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $intent = Intent::of($query, $this->dialect);

        if ($intent === Intent::Read) {
            return $this->pdo->query($query, $fetchMode, ...$fetchModeArgs);
        }

        $statement = new PendingStatement($query, $this->dialect, $intent === Intent::Write ? $this->recording : null);
        $statement->execute();

        return $statement;
    }

    public function beginTransaction(): bool
    {
        $this->transactions++;

        return true;
    }

    public function commit(): bool
    {
        $this->transactions = max(0, $this->transactions - 1);

        return true;
    }

    public function rollBack(): bool
    {
        $this->transactions = max(0, $this->transactions - 1);

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactions > 0 || $this->pdo->inTransaction();
    }

    public function lastInsertId(?string $name = null): string
    {
        return '0';
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->pdo->getAttribute($attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->pdo->quote($string, $type);
    }

    public function errorCode(): ?string
    {
        return $this->pdo->errorCode();
    }

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return $this->pdo->errorInfo();
    }
}

<?php

declare(strict_types=1);

namespace StrictMigrations\Analysis;

use StrictMigrations\Database\Engine;
use StrictMigrations\Database\Inspector;
use StrictMigrations\Database\Server;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Kind;
use StrictMigrations\Findings\Severity;
use StrictMigrations\Findings\TableSize;
use StrictMigrations\Settings;

/**
 * Everything an operation needs to judge itself while a migration is assessed statement by
 * statement, and the one place that turns a risk into a severity: a lock on a small table
 * is only a warning, nothing on a table created by the run is a risk at all, and a statement
 * the migration vouched for is reported as assured.
 */
final class Context
{
    private string $statement = '';

    private bool $assured = false;

    /** @var array<string, true> */
    private array $altered = [];

    private ?bool $foreignKeyChecks = null;

    public function __construct(
        public readonly Server $server,
        public readonly Inspector $inspector,
        public readonly Settings $settings,
        public readonly Run $run,
        public readonly bool $transactional,
        public readonly string $prefix = '',
    ) {}

    public function engine(): Engine
    {
        return $this->server->engine;
    }

    /** The name Schema::table() expects for a table: without the connection's prefix. */
    public function blueprintTable(string $table): string
    {
        $segments = explode('.', $table);
        $name = array_pop($segments);

        if ($this->prefix !== '' && str_starts_with($name, $this->prefix)) {
            $name = substr($name, strlen($this->prefix));
        }

        return implode('.', [...$segments, $name]);
    }

    /** An identifier quoted for the engine, for the SQL written in recipes. */
    public function quote(string $identifier): string
    {
        $quote = $this->engine()->isMySqlFamily() ? '`' : '"';

        return implode('.', array_map(
            fn (string $segment): string => $quote.str_replace($quote, $quote.$quote, $segment).$quote,
            explode('.', $identifier),
        ));
    }

    public function assessing(string $statement, bool $assured): void
    {
        $this->statement = $statement;
        $this->assured = $assured;
    }

    public function created(string $table): bool
    {
        return $this->run->created($table);
    }

    /** Whether the table holds rows: null when that is not known. */
    public function populated(string $table): ?bool
    {
        if ($this->created($table)) {
            return false;
        }

        $rows = $this->inspector->rows($table);

        return $rows === null ? null : $rows > 0;
    }

    public function alter(string $table): void
    {
        $this->altered[$table] = true;
    }

    /** Whether an earlier statement of the migration changed the table's structure. */
    public function altered(string $table): bool
    {
        return isset($this->altered[$table]);
    }

    public function foreignKeyChecks(): bool
    {
        return $this->foreignKeyChecks ?? $this->inspector->foreignKeyChecks();
    }

    public function checkForeignKeys(bool $checks): void
    {
        $this->foreignKeyChecks = $checks;
    }

    /** A risk that grows with the table: refused on a large table, a warning on a small one. */
    public function lock(string $rule, string $table, string $message, ?string $recipe = null): ?Finding
    {
        return $this->sized(Kind::Lock, $rule, $table, $message, $recipe);
    }

    public function data(string $rule, string $table, string $message, ?string $recipe = null): ?Finding
    {
        return $this->sized(Kind::Data, $rule, $table, $message, $recipe);
    }

    /** A statement that deletes rows: only a risk when the table holds some. */
    public function destroys(string $rule, string $table, string $message, ?string $recipe = null): ?Finding
    {
        if ($this->populated($table) === false) {
            return null;
        }

        $size = new TableSize($table, $this->inspector->rows($table), $this->inspector->limit());

        return $this->finding(Severity::Unsafe, Kind::Data, $rule, $message, $recipe, $size);
    }

    /** A risk for the code still running during a rolling deployment, whatever the size of the table. */
    public function deploy(string $rule, string $table, string $message, ?string $recipe = null): ?Finding
    {
        if ($this->created($table)) {
            return null;
        }

        return $this->finding($this->settings->rolling() ? Severity::Unsafe : Severity::Note, Kind::Deploy, $rule, $message, $recipe);
    }

    /** A statement that will fail as written. */
    public function error(string $rule, string $message, ?string $recipe = null): Finding
    {
        return $this->finding(Severity::Unsafe, Kind::Error, $rule, $message, $recipe);
    }

    /** A risk the lock timeout bounds, worth knowing whatever the size of the table. */
    public function warn(string $rule, string $message, ?string $recipe = null): Finding
    {
        return $this->finding(Severity::Warning, Kind::Lock, $rule, $message, $recipe);
    }

    public function note(string $rule, string $message, ?string $recipe = null): Finding
    {
        return $this->finding(Severity::Note, Kind::Lock, $rule, $message, $recipe);
    }

    public function unread(string $message, ?string $recipe = null): Finding
    {
        return $this->finding(Severity::Unsafe, Kind::Unread, 'unread', $message, $recipe);
    }

    private function sized(Kind $kind, string $rule, string $table, string $message, ?string $recipe): ?Finding
    {
        if ($this->created($table)) {
            return null;
        }

        $size = new TableSize($table, $this->inspector->rows($table), $this->inspector->limit());

        return $this->finding($size->small() ? Severity::Warning : Severity::Unsafe, $kind, $rule, $message, $recipe, $size);
    }

    private function finding(Severity $severity, Kind $kind, string $rule, string $message, ?string $recipe, ?TableSize $size = null): Finding
    {
        if ($this->assured && $severity !== Severity::Note) {
            $severity = Severity::Assured;
        }

        return new Finding($severity, $kind, $rule, $message, $this->statement, $recipe, $size);
    }
}

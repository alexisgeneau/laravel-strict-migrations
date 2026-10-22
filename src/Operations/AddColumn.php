<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Findings;

final readonly class AddColumn implements Operation
{
    public function __construct(
        public string $table,
        public string $column,
        public ColumnDefinition $definition,
        public AlterOptions $options = new AlterOptions,
    ) {}

    public function assess(Context $context): array
    {
        return Findings::of(...match ($context->engine()) {
            Engine::Postgres => $this->onPostgres($context),
            Engine::MySql, Engine::MariaDb => $this->onMySql($context),
            Engine::Sqlite => $this->onSqlite($context),
        });
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }

    /** @return list<Finding|null> */
    private function onPostgres(Context $context): array
    {
        $definition = $this->definition;
        $column = $context->quote($this->column);
        $table = $context->quote($this->table);

        return [
            match (true) {
                $definition->autoIncrement, $definition->generated === 'stored' => $context->lock(
                    'column-rewrite',
                    $this->table,
                    sprintf('Adding %s as %s rewrites %s while blocking reads and writes: every existing row gets its value.', $this->column, $definition->autoIncrement ? 'an identity or serial column' : 'a stored generated column', $this->table),
                    'Add a plain nullable column instead, fill it in batches outside the migration\'s transaction, and attach the default or the expression in a later migration.',
                ),
                $definition->volatileDefault() || ($definition->default !== null && ! $context->server->atLeast('11')) => $context->lock(
                    'column-default',
                    $this->table,
                    "Adding {$this->column} with the default {$definition->default} rewrites {$this->table} while blocking reads and writes: the default is computed for every existing row.",
                    implode("\n", [
                        'Add the column without a default, which is instant, then set the default for the rows to come:',
                        '',
                        Php::statement("alter table {$table} add column {$column} {$definition->type} null"),
                        Php::statement("alter table {$table} alter column {$column} set default {$definition->default}"),
                        '',
                        'Fill the existing rows in batches (chunkById), outside the migration\'s transaction.',
                    ]),
                ),
                $definition->nullable === false && $definition->default === null && $context->populated($this->table) !== false => $context->error(
                    'not-null-without-default',
                    "Adding {$this->column} as NOT NULL without a default fails: the rows already in {$this->table} have no value for it.",
                    'Give the column a default, or add it nullable, fill it in batches and make it NOT NULL in a later migration.',
                ),
                default => null,
            },
            $definition->unique || ($definition->primary && ! $definition->autoIncrement) ? $context->lock(
                'unique-constraint',
                $this->table,
                sprintf('Adding %s as a %s builds its index while blocking reads and writes on %s.', $this->column, $definition->primary ? 'primary key' : 'unique column', $this->table),
                'Add the column without the constraint, build a unique index concurrently, then attach it to the constraint, which is instant.',
            ) : null,
            $definition->type === 'json' ? $context->note('json', "{$this->table}.{$this->column} is json: jsonb can be indexed and compared, json cannot.", '    $table->jsonb('.Php::string($this->column).');') : null,
        ];
    }

    /** @return list<Finding|null> */
    private function onMySql(Context $context): array
    {
        if ($this->options->keepsWrites()) {
            return [];
        }

        $definition = $this->definition;

        return [
            match (true) {
                $definition->autoIncrement => $context->lock(
                    'auto-increment',
                    $this->table,
                    "Adding the auto-increment column {$this->column} blocks writes to {$this->table} while the table is rebuilt.",
                    'MySQL cannot add it without blocking writes: run it in a maintenance window or through gh-ost or pt-online-schema-change.',
                ),
                $definition->generated === 'stored' => $context->lock(
                    'column-rewrite',
                    $this->table,
                    "Adding the stored generated column {$this->column} copies {$this->table} while blocking writes.",
                    'A virtual generated column is added instantly, and can be indexed: ->virtualAs() instead of ->storedAs().',
                ),
                $definition->expressionDefault() => $context->lock(
                    'column-default',
                    $this->table,
                    "Adding {$this->column} with the expression default {$definition->default} copies {$this->table} while blocking writes.",
                    implode("\n", [
                        'Add the column without a default, which is instant, then set the default:',
                        '',
                        Php::statement("alter table {$context->quote($this->table)} alter column {$context->quote($this->column)} set default {$definition->default}"),
                    ]),
                ),
                default => null,
            },
            $definition->nullable === false && $definition->default === null && preg_match('/^(date|datetime)\b/', $definition->type) === 1 && $context->populated($this->table) !== false ? $context->error(
                'not-null-without-default',
                "Adding {$this->column} as a NOT NULL {$definition->type} without a default fails: the rows already in {$this->table} would get the zero date, which strict mode refuses.",
                'Give the column a default, or add it nullable, fill it and make it NOT NULL in a later migration.',
            ) : null,
        ];
    }

    /** @return list<Finding|null> */
    private function onSqlite(Context $context): array
    {
        $definition = $this->definition;

        if ($definition->primary || $definition->unique) {
            return [$context->error(
                'sqlite-add-column',
                sprintf('SQLite cannot add a %s column: the statement fails.', $definition->primary ? 'PRIMARY KEY' : 'UNIQUE'),
                'Add the column without the constraint, then a unique index in its own Schema::table() call.',
            )];
        }

        [$what, $recipe] = match (true) {
            $definition->generated === 'stored' => ['a stored generated column', 'A virtual generated column can be added: ->virtualAs() instead of ->storedAs().'],
            $definition->nullable === false && $definition->default === null => ["{$this->column} as NOT NULL without a default", 'Give the column a default, or make it nullable.'],
            $definition->nonConstantDefault() => ["{$this->column} with the default {$definition->default}, which is not a constant", 'Add the column nullable, or with a constant default, and fill it afterwards.'],
            default => [null, null],
        };

        if ($what === null || $context->populated($this->table) === false) {
            return [];
        }

        return [$context->error('sqlite-add-column', "SQLite cannot add {$what} to a table that holds rows: the statement fails.", $recipe)];
    }
}

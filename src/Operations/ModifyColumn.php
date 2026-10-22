<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\MySqlTypes;
use StrictMigrations\Findings\Findings;

/** MODIFY or CHANGE on MySQL and MariaDB: the whole new definition of a column, renamed or not. */
final readonly class ModifyColumn implements Operation
{
    public function __construct(
        public string $table,
        public string $column,
        public ColumnDefinition $definition,
        public ?string $renameTo = null,
        public AlterOptions $options = new AlterOptions,
    ) {}

    public function assess(Context $context): array
    {
        $findings = $this->renameTo !== null && $this->renameTo !== $this->column
            ? (new RenameColumn($this->table, $this->column, $this->renameTo))->assess($context)
            : [];

        if ($context->created($this->table) || $this->options->keepsWrites()) {
            return $findings;
        }

        $current = $context->inspector->column($this->table, $this->column);
        $algorithm = $current === null ? MySqlTypes::COPY : MySqlTypes::algorithm($current, $this->definition, $context->engine());

        if ($algorithm === MySqlTypes::COPY) {
            $findings[] = $context->lock(
                'column-type',
                $this->table,
                sprintf('Changing %s.%s from %s to %s copies %s while blocking writes.', $this->table, $this->column, $current === null ? 'its current definition' : MySqlTypes::canonical($current->type), MySqlTypes::canonical($this->definition->type), $this->table),
                implode("\n", [
                    'Change it over several deployments:',
                    '1. add a column of the new type, and write to both;',
                    '2. fill it for the existing rows in batches;',
                    '3. switch the code to the new column, then drop the old one.',
                    '',
                    'Or run the change through gh-ost or pt-online-schema-change, which copy the table without blocking writes.',
                    'A new default, a value appended to an ENUM, or a longer VARCHAR within the same length prefix change in place.',
                ]),
            );
        } elseif ($current?->nullable && $this->definition->nullable === false) {
            $findings[] = $context->warn(
                'not-null',
                "{$this->table} is rebuilt in place to make {$this->column} NOT NULL: writes continue, and the statement fails if a row holds NULL.",
                '->change() keeps only the modifiers it is given: add ->nullable() to keep the column nullable.',
            );
        }

        return Findings::of(...$findings);
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }
}

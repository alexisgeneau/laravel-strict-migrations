<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Findings;

final readonly class AddForeignKey implements Operation
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public string $references,
        public array $referencedColumns,
        public bool $notValid = false,
        public AlterOptions $options = new AlterOptions,
    ) {}

    public function assess(Context $context): array
    {
        if ($context->created($this->table)) {
            return [];
        }

        return Findings::of(...match ($context->engine()) {
            Engine::Postgres => [$this->onPostgres($context)],
            Engine::MySql, Engine::MariaDb => [$this->onMySql($context)],
            Engine::Sqlite => [],
        });
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }

    private function onPostgres(Context $context): ?Finding
    {
        $validate = Php::statement("alter table {$context->quote($this->table)} validate constraint {$context->quote($this->name)}");

        if ($this->notValid) {
            return $context->note('foreign-key', "{$this->name} is added without checking the existing rows: validate it in a later migration, which does not block writes.", $validate);
        }

        return $context->lock(
            'foreign-key',
            $this->table,
            "Adding the foreign key {$this->name} blocks writes to {$this->table} and {$this->references} while every row of {$this->table} is checked.",
            implode("\n", [
                'Add ->notValid() to the foreign key: it is then added without checking the existing rows, which is instant.',
                'Validate it in a later migration, which checks the rows without blocking writes:',
                '',
                $validate,
            ]),
        );
    }

    private function onMySql(Context $context): ?Finding
    {
        if (! $context->foreignKeyChecks()) {
            return null;
        }

        $recipe = implode("\n", [
            'With foreign key checks off, the foreign key is added in place without blocking writes, and without checking the existing rows:',
            '',
            '    Schema::withoutForeignKeyConstraints(function () {',
            '        Schema::table('.Php::string($context->blueprintTable($this->table)).', function (Blueprint $table) {',
            '            $table->foreign('.Php::list($this->columns).')->references('.Php::list($this->referencedColumns).')->on('.Php::string($context->blueprintTable($this->references)).');',
            '        });',
            '    });',
        ]);

        if ($this->options->keepsWrites() || $this->options->algorithm === 'inplace') {
            return $context->error('foreign-key', "Adding the foreign key {$this->name} without copying {$this->table} fails while foreign key checks are on: MySQL refuses the statement.", $recipe);
        }

        return $context->lock('foreign-key', $this->table, "Adding the foreign key {$this->name} copies {$this->table} while blocking writes, because foreign key checks are on.", $recipe);
    }
}

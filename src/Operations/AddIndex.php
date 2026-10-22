<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Analysis\Context;
use StrictMigrations\Database\Engine;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Findings;

final readonly class AddIndex implements Operation
{
    /**
     * @param  list<string>  $columns  Column names, or the text of the expressions indexed.
     * @param  string|null  $method  gin, gist, fulltext, spatial...
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public string $statement,
        public bool $unique = false,
        public bool $concurrently = false,
        public ?string $method = null,
        public AlterOptions $options = new AlterOptions,
    ) {}

    public function assess(Context $context): array
    {
        if ($this->concurrently && $context->transactional) {
            return [$context->error('index-in-transaction', 'create index concurrently cannot run inside a transaction: the migration would fail.', "Run the migration outside a transaction:\n\n    public \$withinTransaction = false;")];
        }

        if ($context->created($this->table)) {
            return [];
        }

        return Findings::of(...match ($context->engine()) {
            Engine::Postgres => $this->onPostgres($context),
            Engine::MySql, Engine::MariaDb => $this->onMySql($context),
            Engine::Sqlite => [],
        });
    }

    public function apply(Context $context): void
    {
        $context->alter($this->table);
    }

    /** @return list<Finding|null> */
    private function onPostgres(Context $context): array
    {
        if ($this->concurrently) {
            return [$context->note('index', 'A concurrent build that fails leaves an invalid index behind: drop it, concurrently too, before running the migration again.')];
        }

        return [$context->lock(
            'index',
            $this->table,
            "Building {$this->name} blocks every write to {$this->table} until the index is built.",
            implode("\n", [
                'Build it concurrently, which does not block writes, in a migration that runs outside a transaction:',
                '',
                '    public $withinTransaction = false;',
                '',
                ...$this->concurrentForm($context),
                '',
                'A concurrent build that fails leaves an invalid index behind: drop it before running the migration again.',
            ]),
        )];
    }

    /** @return list<Finding|null> */
    private function onMySql(Context $context): array
    {
        if (in_array($this->method, ['fulltext', 'spatial'], true) && ! $this->options->keepsWrites()) {
            return [$context->lock(
                'index',
                $this->table,
                sprintf('Adding the %s index %s blocks writes to %s while it is built.', strtoupper($this->method), $this->name, $this->table),
                'MySQL cannot build it without blocking writes: run it in a maintenance window, or through gh-ost or pt-online-schema-change.',
            )];
        }

        return [$context->note('index', "{$this->name} is built in place: writes continue, after a brief metadata lock that waits for the transactions running on {$this->table}.")];
    }

    /** @return list<string> */
    private function concurrentForm(Context $context): array
    {
        $plain = $this->method === null && array_filter($this->columns, fn (string $column): bool => preg_match('/^\w+$/', $column) !== 1) === [];

        if (! $plain) {
            return [Php::statement((string) preg_replace('/^\s*create\s+(unique\s+)?index\s+/i', 'create $1index concurrently ', $this->statement))];
        }

        $type = $this->unique ? 'unique' : 'index';
        $default = str_replace(['-', '.'], '_', strtolower($this->table.'_'.implode('_', $this->columns).'_'.$type));
        $arguments = Php::list($this->columns).($this->name === $default ? '' : ', '.Php::string($this->name));

        return [
            '    Schema::table('.Php::string($context->blueprintTable($this->table)).', function (Blueprint $table) {',
            "        \$table->{$type}({$arguments})->online();",
            '    });',
        ];
    }
}

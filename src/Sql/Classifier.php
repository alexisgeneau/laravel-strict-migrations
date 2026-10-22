<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

use StrictMigrations\Operations\AddCheck;
use StrictMigrations\Operations\AddColumn;
use StrictMigrations\Operations\AddForeignKey;
use StrictMigrations\Operations\AddIndex;
use StrictMigrations\Operations\AddPrimaryKey;
use StrictMigrations\Operations\AddUniqueConstraint;
use StrictMigrations\Operations\AlterOptions;
use StrictMigrations\Operations\AlterTable;
use StrictMigrations\Operations\ChangeColumnType;
use StrictMigrations\Operations\ColumnDefinition;
use StrictMigrations\Operations\CreateTable;
use StrictMigrations\Operations\DataChange;
use StrictMigrations\Operations\DropColumn;
use StrictMigrations\Operations\DropIndex;
use StrictMigrations\Operations\DropPrimaryKey;
use StrictMigrations\Operations\DropTable;
use StrictMigrations\Operations\Harmless;
use StrictMigrations\Operations\LockTable;
use StrictMigrations\Operations\Maintenance;
use StrictMigrations\Operations\ModifyColumn;
use StrictMigrations\Operations\Operation;
use StrictMigrations\Operations\RebuildTable;
use StrictMigrations\Operations\RenameColumn;
use StrictMigrations\Operations\RenameTable;
use StrictMigrations\Operations\SessionSetting;
use StrictMigrations\Operations\SetNotNull;
use StrictMigrations\Operations\TruncateTable;
use StrictMigrations\Operations\Unread;

/**
 * Reads one SQL statement into the operations it performs, whether Laravel's grammars wrote
 * it or a migration passed it to DB::statement(). What it does not recognize comes out as
 * Unread, never as harmless.
 */
final class Classifier
{
    /** Objects whose creation or removal locks no table: views, routines, types, grants... */
    private const OBJECTS = [
        'view', 'materialized', 'function', 'procedure', 'trigger', 'type', 'extension', 'sequence', 'domain',
        'policy', 'rule', 'aggregate', 'operator', 'collation', 'publication', 'subscription', 'event', 'role',
        'user', 'statistics', 'cast', 'server', 'text', 'conversion', 'language', 'transform', 'access', 'tablespace',
        'definer', 'algorithm', 'sql', 'foreign', 'routine', 'group',
    ];

    /** @return list<Operation> */
    public function classify(string $statement, Dialect $dialect): array
    {
        $tokens = Tokens::of($statement, $dialect);
        $first = $tokens->peek();

        if ($first === null) {
            return [];
        }

        if ($first->type !== TokenType::Word) {
            return [new Unread];
        }

        return match ($first->value) {
            'alter' => $this->alter($tokens, $statement, $dialect),
            'create' => $this->create($tokens, $statement, $dialect),
            'drop' => $this->drop($tokens),
            'rename' => $this->renameTables($tokens),
            'truncate' => $this->truncate($tokens),
            'insert', 'replace', 'update', 'delete', 'merge', 'with' => $this->data($tokens),
            'set' => $this->set($tokens),
            'select' => $this->select($tokens),
            'vacuum' => $this->vacuum($tokens),
            'reindex' => $this->reindex($tokens),
            'refresh' => $this->refresh($tokens),
            'cluster' => $this->cluster($tokens),
            'lock' => $this->lock($tokens),
            'comment', 'grant', 'revoke', 'analyze', 'analyse', 'notify', 'listen', 'unlisten', 'discard', 'reset',
            'checkpoint', 'pragma', 'optimize', 'flush', 'security', 'load', 'deallocate', 'prepare' => [new Harmless],
            default => [new Unread],
        };
    }

    /** @return list<Operation> */
    private function alter(Tokens $tokens, string $statement, Dialect $dialect): array
    {
        $tokens->next();
        $tokens->acceptOne('online', 'ignore');

        if ($tokens->accept('table')) {
            return $this->alterTable($tokens, $statement, $dialect);
        }

        return $tokens->acceptOne('index', ...self::OBJECTS) !== null ? [new Harmless] : [new Unread];
    }

    /** @return list<Operation> */
    private function alterTable(Tokens $tokens, string $statement, Dialect $dialect): array
    {
        $tokens->accept('if', 'exists');
        $tokens->accept('only');
        $table = $tokens->name();
        $tokens->acceptSymbol('*');

        if ($table === null) {
            return [new Unread];
        }

        $actions = [];
        $options = new AlterOptions;

        foreach ($tokens->split() as $action) {
            if ($dialect === Dialect::MySql && ($option = $action->acceptOne('algorithm', 'lock')) !== null) {
                $action->acceptSymbol('=');
                $value = $action->next()?->value;
                $options = $option === 'algorithm' ? new AlterOptions($value, $options->lock) : new AlterOptions($options->algorithm, $value);

                continue;
            }

            $actions[] = $action;
        }

        $addsPrimaryKey = array_filter($actions, fn (Tokens $action): bool => $action->startsWith('add', 'primary') || ($action->startsWith('add', 'constraint') && $action->containsAtTop('primary'))) !== [];
        $operations = [];

        foreach ($actions as $action) {
            array_push($operations, ...$this->action($table, $action, $statement, $dialect, $options, $addsPrimaryKey));
        }

        return $operations;
    }

    /** @return list<Operation> */
    private function action(string $table, Tokens $action, string $statement, Dialect $dialect, AlterOptions $options, bool $addsPrimaryKey): array
    {
        return match (true) {
            $action->accept('add') => $this->add($table, $action, $statement, $dialect, $options),
            $action->accept('drop') => $this->dropFromTable($table, $action, $options, $addsPrimaryKey),
            $action->accept('alter') => $this->alterColumn($table, $action),
            $action->accept('modify') => $this->modify($table, $action, $options),
            $action->accept('change') => $this->change($table, $action, $options),
            $action->accept('rename') => $this->rename($table, $action),
            $action->accept('convert', 'to') => [new RebuildTable($table, 'converting its character set')],
            $action->accept('order', 'by') => [new RebuildTable($table, 'ordering its rows')],
            $action->accept('set') => $this->setOnTable($table, $action),
            $action->accept('engine') => $this->engine($table, $action),
            $action->startsWith('attach|partition|remove|coalesce|reorganize|exchange|rebuild|repair|discard|import') => [new Unread],
            default => [new AlterTable($table)],
        };
    }

    /** @return list<Operation> */
    private function add(string $table, Tokens $action, string $statement, Dialect $dialect, AlterOptions $options): array
    {
        $constraint = $action->accept('constraint') ? $action->name() : null;

        if ($action->accept('primary', 'key')) {
            return $action->accept('using', 'index') ? [new AlterTable($table)] : [new AddPrimaryKey($table, $this->columns($action))];
        }

        if ($action->accept('unique')) {
            $action->acceptOne('index', 'key');

            if ($dialect !== Dialect::Postgres) {
                $name = $this->indexName($action) ?? $constraint;

                return [new AddIndex($table, $name ?? $table.'_unique', $this->columns($action), $statement, true, false, null, $options)];
            }

            if ($action->accept('using', 'index')) {
                return [new AddUniqueConstraint($table, (string) $constraint, [], true)];
            }

            if (! $action->accept('nulls', 'not', 'distinct')) {
                $action->accept('nulls', 'distinct');
            }

            return [new AddUniqueConstraint($table, $constraint ?? $table.'_unique', $action->names())];
        }

        if ($action->accept('foreign', 'key')) {
            if ($action->peek()?->isName()) {
                $action->name();
            }

            $columns = $action->names();
            $action->accept('references');
            $references = (string) $action->name();

            return [new AddForeignKey($table, $constraint ?? $table.'_foreign', $columns, $references, $action->names(), $this->containsSequence($action, 'not', 'valid'), $options)];
        }

        if ($action->accept('check')) {
            $expression = $action->group()?->text() ?? '';

            return [new AddCheck($table, $constraint ?? $table.'_check', $expression, $this->containsSequence($action, 'not', 'valid'))];
        }

        if ($action->startsWith('exclude')) {
            return [new Unread];
        }

        if ($dialect === Dialect::MySql && ($method = $action->acceptOne('fulltext', 'spatial', 'index', 'key')) !== null) {
            $action->acceptOne('index', 'key');
            $name = $this->indexName($action);
            $columns = $this->columns($action);

            return [new AddIndex($table, $name ?? $columns[0] ?? $table, $columns, $statement, false, false, in_array($method, ['fulltext', 'spatial'], true) ? $method : null, $options)];
        }

        $action->accept('column');
        $action->accept('if', 'not', 'exists');

        if (($group = $action->group()) !== null) {
            return array_map(fn (Tokens $column): Operation => $this->addColumn($table, $column, $options), $group->split());
        }

        return [$this->addColumn($table, $action, $options)];
    }

    private function addColumn(string $table, Tokens $definition, AlterOptions $options): Operation
    {
        $column = $definition->name();

        return $column === null ? new Unread : new AddColumn($table, $column, ColumnDefinition::parse($definition), $options);
    }

    /** @return list<Operation> */
    private function dropFromTable(string $table, Tokens $action, AlterOptions $options, bool $addsPrimaryKey): array
    {
        if ($action->accept('primary', 'key')) {
            return [$addsPrimaryKey ? new AlterTable($table) : new DropPrimaryKey($table, $options)];
        }

        if ($action->startsWith('constraint') || $action->startsWith('foreign', 'key') || $action->startsWith('index|key|check|partition|default|expression|identity')) {
            return [new AlterTable($table)];
        }

        $action->accept('column');
        $action->accept('if', 'exists');
        $column = $action->name();

        return $column === null ? [new Unread] : [new DropColumn($table, $column)];
    }

    /** @return list<Operation> */
    private function alterColumn(string $table, Tokens $action): array
    {
        if ($action->startsWith('constraint|index|check')) {
            return [new AlterTable($table)];
        }

        $action->accept('column');
        $column = $action->name();

        if ($column === null) {
            return [new Unread];
        }

        if ($action->accept('type') || $action->accept('set', 'data', 'type')) {
            $using = $action->containsAtTop('using');

            return [new ChangeColumnType($table, $column, ColumnDefinition::parse($action)->type, $using)];
        }

        if ($action->accept('set', 'not', 'null')) {
            return [new SetNotNull($table, $column)];
        }

        return [new AlterTable($table)];
    }

    /** @return list<Operation> */
    private function modify(string $table, Tokens $action, AlterOptions $options): array
    {
        $action->accept('column');
        $column = $action->name();

        return $column === null ? [new Unread] : [new ModifyColumn($table, $column, ColumnDefinition::parse($action), null, $options)];
    }

    /** @return list<Operation> */
    private function change(string $table, Tokens $action, AlterOptions $options): array
    {
        $action->accept('column');
        $column = $action->name();
        $renameTo = $action->name();

        return $column === null || $renameTo === null ? [new Unread] : [new ModifyColumn($table, $column, ColumnDefinition::parse($action), $renameTo, $options)];
    }

    /** @return list<Operation> */
    private function rename(string $table, Tokens $action): array
    {
        if ($action->startsWith('index|key|constraint')) {
            return [new AlterTable($table)];
        }

        if ($action->accept('column') || ($action->peek(1)?->is('to') && ! $action->peek()?->is('to', 'as'))) {
            $from = (string) $action->name();
            $action->accept('to');

            return [new RenameColumn($table, $from, (string) $action->name())];
        }

        $action->acceptOne('to', 'as');
        $to = $action->name();

        return $to === null ? [new Unread] : [new RenameTable($table, $to)];
    }

    /** @return list<Operation> */
    private function setOnTable(string $table, Tokens $action): array
    {
        if (($logging = $action->acceptOne('logged', 'unlogged')) !== null) {
            return [new RebuildTable($table, 'set '.$logging)];
        }

        if ($action->startsWith('tablespace') || $action->startsWith('access', 'method')) {
            return [new RebuildTable($table, 'moving it to another '.($action->startsWith('tablespace') ? 'tablespace' : 'access method'))];
        }

        if ($action->accept('schema')) {
            $schema = (string) $action->name();

            return [new RenameTable($table, $schema.'.'.substr((string) strrchr('.'.$table, '.'), 1))];
        }

        return [new AlterTable($table)];
    }

    /** @return list<Operation> */
    private function engine(string $table, Tokens $action): array
    {
        $action->acceptSymbol('=');

        return $action->acceptOne('innodb') !== null ? [new AlterTable($table)] : [new RebuildTable($table, 'changing its storage engine')];
    }

    /** @return list<Operation> */
    private function create(Tokens $tokens, string $statement, Dialect $dialect): array
    {
        $tokens->next();
        $tokens->accept('or', 'replace');

        $unique = $tokens->accept('unique');
        $method = $unique ? null : $tokens->acceptOne('fulltext', 'spatial');

        if ($tokens->accept('index')) {
            return [$this->createIndex($tokens, $statement, $unique, $method)];
        }

        $temporary = $tokens->acceptOne('temporary', 'temp', 'unlogged', 'global', 'local');

        if ($tokens->accept('table') || ($temporary !== null && $tokens->accept('temporary', 'table'))) {
            return [$this->createTable($tokens, in_array($temporary, ['temporary', 'temp', 'global', 'local'], true))];
        }

        return $tokens->acceptOne(...self::OBJECTS) !== null || $tokens->acceptOne('schema') !== null ? [new Harmless] : [new Unread];
    }

    private function createIndex(Tokens $tokens, string $statement, bool $unique, ?string $method): Operation
    {
        $concurrently = $tokens->accept('concurrently');
        $tokens->accept('if', 'not', 'exists');
        $name = $tokens->peek()?->is('on') ? null : $tokens->name();

        if ($tokens->accept('using')) {
            $tokens->next();
        }

        $tokens->accept('on');
        $tokens->accept('only');
        $table = $tokens->name();

        if ($table === null) {
            return new Unread;
        }

        if ($tokens->accept('using')) {
            $method ??= $tokens->next()?->value;
        }

        $columns = $tokens->names();

        return new AddIndex($table, $name ?? $table.'_index', $columns, $statement, $unique, $concurrently, $method, $this->trailingOptions($tokens));
    }

    private function createTable(Tokens $tokens, bool $temporary): Operation
    {
        $tokens->accept('if', 'not', 'exists');
        $table = $tokens->name();

        if ($table === null) {
            return new Unread;
        }

        $json = [];

        foreach ($tokens->group()?->split() ?? [] as $element) {
            if ($element->startsWith('constraint|primary|unique|foreign|check|key|index|fulltext|spatial|exclude|like|period')) {
                continue;
            }

            $column = $element->name();

            if ($column !== null && ColumnDefinition::parse($element)->type === 'json') {
                $json[] = $column;
            }
        }

        return new CreateTable($table, $temporary, $json);
    }

    /** @return list<Operation> */
    private function drop(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->acceptOne('temporary');

        if ($tokens->accept('table')) {
            $ifExists = $tokens->accept('if', 'exists');

            return array_map(fn (Tokens $part): Operation => ($name = $part->name()) === null ? new Unread : new DropTable($name, $ifExists), $tokens->split());
        }

        if ($tokens->accept('index')) {
            $concurrently = $tokens->accept('concurrently');
            $tokens->accept('if', 'exists');
            $name = $tokens->name();

            if ($name === null) {
                return [new Unread];
            }

            return [new DropIndex($name, $tokens->accept('on') ? $tokens->name() : null, $concurrently)];
        }

        return $tokens->acceptOne(...self::OBJECTS) !== null ? [new Harmless] : [new Unread];
    }

    /** @return list<Operation> */
    private function renameTables(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->accept('table');

        return array_map(function (Tokens $part): Operation {
            $from = $part->name();
            $part->accept('to');
            $to = $part->name();

            return $from === null || $to === null ? new Unread : new RenameTable($from, $to);
        }, $tokens->split());
    }

    /** @return list<Operation> */
    private function truncate(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->accept('table');
        $tokens->accept('only');

        return array_map(fn (Tokens $part): Operation => ($name = $part->name()) === null ? new Unread : new TruncateTable($name), $tokens->split());
    }

    /** @return list<Operation> */
    private function data(Tokens $tokens): array
    {
        if ($tokens->peek()?->is('with') && ! $tokens->seek(fn (Token $token): bool => $token->is('insert', 'update', 'delete', 'merge'))) {
            return [new Unread];
        }

        $verb = (string) $tokens->next()?->value;
        $tokens->acceptOne('low_priority', 'delayed', 'high_priority', 'quick', 'ignore');

        if (in_array($verb, ['insert', 'replace', 'merge'], true)) {
            $tokens->accept('into');
        } elseif ($verb === 'delete') {
            $tokens->accept('from');
        }

        $tokens->accept('only');
        $table = $tokens->name();

        if ($table === null) {
            return [new Unread];
        }

        return [new DataChange($verb === 'replace' ? 'insert' : $verb, $table, in_array($verb, ['update', 'delete'], true) && ! $tokens->containsAtTop('where'))];
    }

    /** @return list<Operation> */
    private function set(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->acceptOne('session', 'global', 'local', 'persist', 'persist_only');

        if ($tokens->startsWith('constraints|transaction|names|role|time|search_path')) {
            return [new Harmless];
        }

        return array_map(function (Tokens $assignment): Operation {
            while ($assignment->acceptSymbol('@')) {
                $assignment->acceptOne('session', 'global');
                $assignment->acceptSymbol('.');
            }

            $name = (string) $assignment->name();

            if (! $assignment->acceptSymbol('=')) {
                $assignment->accept('to');
            }

            return new SessionSetting(strtolower(substr((string) strrchr('.'.$name, '.'), 1)), $assignment->text());
        }, $tokens->split());
    }

    /** @return list<Operation> */
    private function select(Tokens $tokens): array
    {
        $tokens->until(fn (Token $token): bool => $token->is('into'));

        if (! $tokens->accept('into') || $tokens->peek()?->isSymbol('@') || $tokens->startsWith('outfile|dumpfile')) {
            return [new Harmless];
        }

        $temporary = $tokens->acceptOne('temporary', 'temp', 'unlogged');
        $tokens->accept('table');
        $table = $tokens->name();

        return $table === null ? [new Unread] : [new CreateTable($table, in_array($temporary, ['temporary', 'temp'], true))];
    }

    /** @return list<Operation> */
    private function vacuum(Tokens $tokens): array
    {
        $tokens->next();
        $full = false;

        while (($keyword = $tokens->acceptOne('full', 'freeze', 'verbose', 'analyze', 'analyse')) !== null) {
            $full = $full || $keyword === 'full';
        }

        if (($options = $tokens->group()) !== null) {
            $full = $full || $options->contains('full');
        }

        $table = $tokens->name();

        if (! $full) {
            return [new Maintenance('vacuum', $table)];
        }

        return $table === null ? [new Unread] : [new RebuildTable($table, 'vacuum full')];
    }

    /** @return list<Operation> */
    private function reindex(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->group();
        $kind = $tokens->acceptOne('index', 'table', 'schema', 'database', 'system');
        $concurrently = $tokens->accept('concurrently');
        $target = $tokens->name();

        return [new Maintenance('reindex', in_array($kind, ['index', 'table'], true) ? $target : null, $concurrently)];
    }

    /** @return list<Operation> */
    private function refresh(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->accept('materialized', 'view');
        $concurrently = $tokens->accept('concurrently');

        return [new Maintenance('refresh', $tokens->name(), $concurrently)];
    }

    /** @return list<Operation> */
    private function cluster(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->accept('verbose');
        $table = $tokens->name();

        return $table === null ? [new Unread] : [new RebuildTable($table, 'cluster')];
    }

    /** @return list<Operation> */
    private function lock(Tokens $tokens): array
    {
        $tokens->next();
        $tokens->acceptOne('table', 'tables');
        $tokens->accept('only');

        return array_map(fn (Tokens $part): Operation => ($name = $part->name()) === null ? new Unread : new LockTable($name), $tokens->until(fn (Token $token): bool => $token->is('in', 'nowait'))->split());
    }

    /**
     * The columns of an index or a key, after the optional USING clause of MySQL.
     *
     * @return list<string>
     */
    private function columns(Tokens $tokens): array
    {
        if ($tokens->accept('using')) {
            $tokens->next();
        }

        return $tokens->names();
    }

    /** The ALGORITHM and LOCK options written after a MySQL CREATE INDEX. */
    private function trailingOptions(Tokens $tokens): AlterOptions
    {
        $algorithm = $lock = null;

        while (! $tokens->atEnd()) {
            if (($option = $tokens->acceptOne('algorithm', 'lock')) !== null) {
                $tokens->acceptSymbol('=');
                $value = $tokens->next()?->value;

                if ($option === 'algorithm') {
                    $algorithm = $value;
                } else {
                    $lock = $value;
                }

                continue;
            }

            $tokens->next();
        }

        return new AlterOptions($algorithm, $lock);
    }

    private function containsSequence(Tokens $tokens, string ...$keywords): bool
    {
        $words = array_map(fn (Token $token): string => $token->value, $tokens->remaining());

        return str_contains(' '.implode(' ', $words).' ', ' '.implode(' ', $keywords).' ');
    }

    /** The name MySQL writes before the columns of an index, unless it goes straight to USING or to the columns. */
    private function indexName(Tokens $tokens): ?string
    {
        $next = $tokens->peek();

        return $next !== null && $next->isName() && ! $next->is('using') ? $tokens->name() : null;
    }
}

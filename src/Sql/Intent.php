<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

/**
 * What a statement would do to the database, as far as the dry run needs to know: reads run
 * for real so that a migration sees the actual schema, transaction control is ignored, and
 * everything else is recorded instead of executed.
 */
enum Intent
{
    case Read;
    case Write;
    case Transaction;

    /** Functions that change something even when called from a select. */
    private const EFFECTS = [
        'nextval', 'setval', 'set_config', 'pg_notify', 'pg_reload_conf', 'pg_switch_wal', 'pg_terminate_backend',
        'pg_cancel_backend', 'pg_sleep', 'pg_advisory_lock', 'pg_advisory_lock_shared', 'pg_advisory_xact_lock',
        'pg_advisory_xact_lock_shared', 'pg_try_advisory_lock', 'pg_try_advisory_lock_shared',
        'pg_try_advisory_xact_lock', 'pg_try_advisory_xact_lock_shared', 'pg_advisory_unlock',
        'pg_advisory_unlock_shared', 'pg_advisory_unlock_all', 'lo_import', 'lo_export', 'lo_unlink', 'lo_create',
        'lo_creat', 'dblink_exec', 'get_lock', 'release_lock', 'release_all_locks', 'sleep',
    ];

    /** SQLite pragmas that only read, even when given an argument. */
    private const READING_PRAGMAS = [
        'table_info', 'table_xinfo', 'table_list', 'index_list', 'index_info', 'index_xinfo', 'foreign_key_list',
        'foreign_key_check', 'database_list', 'collation_list', 'function_list', 'module_list', 'pragma_list',
        'compile_options', 'integrity_check', 'quick_check',
    ];

    public static function of(string $sql, Dialect $dialect): self
    {
        $intents = array_map(
            fn (string $statement): self => self::ofStatement(Tokens::of($statement, $dialect)),
            Tokenizer::split($sql, $dialect),
        );

        foreach ([self::Transaction, self::Read] as $intent) {
            if (array_filter($intents, fn (self $other): bool => $other !== $intent) === []) {
                return $intent;
            }
        }

        return self::Write;
    }

    private static function ofStatement(Tokens $tokens): self
    {
        $first = $tokens->peek();

        if ($first === null || $first->type !== TokenType::Word) {
            return self::Write;
        }

        return match ($first->value) {
            'select' => self::select($tokens),
            'with' => $tokens->contains('insert', 'update', 'delete', 'merge') ? self::Write : self::select($tokens),
            'show', 'describe', 'desc', 'values', 'table' => self::Read,
            'explain' => $tokens->contains('analyze', 'analyse') ? self::Write : self::Read,
            'pragma' => self::pragma($tokens),
            'begin', 'start', 'commit', 'end', 'rollback', 'savepoint', 'release' => self::Transaction,
            'set' => $tokens->startsWith('set', 'transaction') ? self::Transaction : self::Write,
            default => self::Write,
        };
    }

    private static function select(Tokens $tokens): self
    {
        if ($tokens->containsAtTop('into')) {
            return self::Write;
        }

        $all = $tokens->remaining();

        foreach ($all as $index => $token) {
            if ($token->isSymbol(':=') || ($token->is(...self::EFFECTS) && ($all[$index + 1] ?? null)?->isSymbol('('))) {
                return self::Write;
            }
        }

        return self::Read;
    }

    private static function pragma(Tokens $tokens): self
    {
        $tokens->next();
        $name = $tokens->name();
        $rest = $tokens->remaining();

        if ($rest === []) {
            return self::Read;
        }

        if ($rest[0]->isSymbol('=')) {
            return self::Write;
        }

        $pragma = $name === null ? null : substr((string) strrchr('.'.$name, '.'), 1);

        return in_array($pragma, self::READING_PRAGMAS, true) ? self::Read : self::Write;
    }
}

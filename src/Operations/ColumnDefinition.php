<?php

declare(strict_types=1);

namespace StrictMigrations\Operations;

use StrictMigrations\Sql\Token;
use StrictMigrations\Sql\Tokens;
use StrictMigrations\Sql\TokenType;

/** A column definition as written in CREATE TABLE, ADD COLUMN, MODIFY or CHANGE. */
final readonly class ColumnDefinition
{
    /** Words that end the type and start a constraint or an option. */
    private const STOPS = [
        'not', 'null', 'default', 'primary', 'unique', 'references', 'check', 'collate', 'generated', 'as',
        'auto_increment', 'autoincrement', 'comment', 'charset', 'first', 'after', 'on', 'constraint', 'visible',
        'invisible', 'srid', 'storage', 'column_format', 'engine_attribute', 'secondary_engine_attribute',
        'compression', 'key', 'stored', 'virtual', 'persistent', 'using',
    ];

    private const SERIALS = ['serial', 'bigserial', 'smallserial', 'serial2', 'serial4', 'serial8'];

    /** PostgreSQL functions that a default may call without making the table rewrite itself. */
    private const STABLE = ['now', 'statement_timestamp', 'transaction_timestamp', 'current_setting', 'to_timestamp', 'make_date', 'make_timestamp'];

    public function __construct(
        public string $type,
        public ?bool $nullable = null,
        public ?string $default = null,
        public bool $autoIncrement = false,
        public ?string $generated = null,
        public bool $primary = false,
        public bool $unique = false,
        public ?string $collation = null,
        public ?string $charset = null,
        public bool $positioned = false,
    ) {}

    public static function parse(Tokens $tokens): self
    {
        $type = self::type($tokens);
        $nullable = $default = $generated = $collation = $charset = null;
        $autoIncrement = in_array($type, self::SERIALS, true);
        $primary = $unique = $positioned = false;

        while (! $tokens->atEnd()) {
            if ($tokens->accept('not', 'null')) {
                $nullable = false;
            } elseif ($tokens->accept('null')) {
                $nullable = true;
            } elseif ($tokens->accept('default', 'null')) {
                $default = null;
            } elseif ($tokens->accept('default')) {
                $default = $tokens->until(fn (Token $token): bool => $token->is(...self::STOPS))->text() ?: null;
            } elseif ($tokens->accept('primary', 'key')) {
                $primary = true;
            } elseif ($tokens->accept('unique')) {
                $unique = true;
                $tokens->acceptOne('key', 'index');
            } elseif ($tokens->acceptOne('auto_increment', 'autoincrement') !== null) {
                $autoIncrement = true;
            } elseif ($tokens->accept('generated')) {
                $tokens->accept('by', 'default') || $tokens->accept('always');
                $tokens->accept('as');

                if ($tokens->accept('identity')) {
                    $autoIncrement = true;
                    $tokens->group();
                } else {
                    $generated = self::generation($tokens);
                }
            } elseif ($tokens->accept('as')) {
                $generated = self::generation($tokens);
            } elseif ($tokens->accept('collate')) {
                $collation = self::word($tokens);
            } elseif ($tokens->accept('character', 'set') || $tokens->accept('charset')) {
                $tokens->acceptSymbol('=');
                $charset = self::word($tokens);
            } elseif ($tokens->acceptOne('first') !== null) {
                $positioned = true;
            } elseif ($tokens->accept('after')) {
                $positioned = true;
                $tokens->name();
            } elseif ($tokens->accept('on')) {
                self::referentialAction($tokens);
            } else {
                self::skip($tokens);
            }
        }

        return new self($type, $nullable, $default, $autoIncrement, $generated, $primary, $unique, $collation, $charset, $positioned);
    }

    /** Whether the default is computed per row, which makes PostgreSQL rewrite the table to add the column. */
    public function volatileDefault(): bool
    {
        if ($this->default === null || ! preg_match_all('/([A-Za-z_][\w.]*)\s*\(/', $this->default, $calls)) {
            return false;
        }

        foreach ($calls[1] as $function) {
            if (! in_array(strtolower(substr((string) strrchr('.'.$function, '.'), 1)), self::STABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether SQLite computes the default for every row written, which it cannot do for the rows a table already holds. */
    public function nonConstantDefault(): bool
    {
        return $this->expressionDefault() || in_array(strtolower(trim((string) $this->default)), ['current_timestamp', 'current_date', 'current_time'], true);
    }

    /** Whether MySQL is given an expression default, such as (uuid()), which it cannot add instantly. */
    public function expressionDefault(): bool
    {
        return $this->default !== null && str_starts_with(ltrim($this->default), '(');
    }

    /** The type in a comparable form: lowercase, without spaces inside its parentheses. */
    private static function type(Tokens $tokens): string
    {
        $type = '';

        while (($token = $tokens->peek()) !== null) {
            if ($token->is(...self::STOPS) || ($token->is('character') && $tokens->peek(1)?->is('set'))) {
                break;
            }

            if ($token->isSymbol('(')) {
                $type .= '('.strtolower((string) preg_replace('/\s*,\s*/', ',', trim($tokens->group()?->text() ?? ''))).')';
            } elseif ($token->isSymbol('[', ']', '.')) {
                $type .= $tokens->next()?->value;
            } elseif ($token->isName()) {
                $type .= ($type === '' || str_ends_with($type, '.') || str_ends_with($type, '[') ? '' : ' ').$tokens->next()?->value;
            } else {
                break;
            }
        }

        return $type;
    }

    private static function generation(Tokens $tokens): string
    {
        $tokens->group();

        if ($tokens->acceptOne('stored', 'persistent') !== null) {
            return 'stored';
        }

        $tokens->acceptOne('virtual');

        return 'virtual';
    }

    /** ON DELETE / ON UPDATE of a reference, or MySQL's ON UPDATE CURRENT_TIMESTAMP. */
    private static function referentialAction(Tokens $tokens): void
    {
        $tokens->acceptOne('delete', 'update');

        if (! $tokens->accept('set', 'null') && ! $tokens->accept('set', 'default') && ! $tokens->accept('no', 'action')
            && $tokens->acceptOne('cascade', 'restrict') === null) {
            $tokens->next();
            $tokens->group();
        }
    }

    private static function word(Tokens $tokens): ?string
    {
        return $tokens->name() ?? ($tokens->peek()?->type === TokenType::String ? $tokens->next()?->value : null);
    }

    private static function skip(Tokens $tokens): void
    {
        if ($tokens->peek()?->isSymbol('(')) {
            $tokens->group();

            return;
        }

        $tokens->next();
    }
}

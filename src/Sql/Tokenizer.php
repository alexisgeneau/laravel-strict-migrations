<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

/**
 * A lexer that knows just enough of each dialect to never mistake the inside of a
 * string, a quoted identifier or a comment for SQL: quoting rules, dollar quotes,
 * backslash escapes and nested comments.
 */
final class Tokenizer
{
    private const SYMBOLS = ['->>', '#>>', '<=>', '::', '->', '#>', '<=', '>=', '<>', '!=', '||', ':=', '@>', '<@', '&&'];

    /** @return list<Token> */
    public static function tokenize(string $sql, Dialect $dialect): array
    {
        $tokens = [];
        $length = strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];

            if (ctype_space($char)) {
                $offset++;

                continue;
            }

            if (($comment = self::comment($sql, $offset, $dialect)) !== null) {
                $offset = $comment;

                continue;
            }

            $token = self::string($sql, $offset, $dialect)
                ?? self::identifier($sql, $offset, $dialect)
                ?? self::number($sql, $offset)
                ?? self::parameter($sql, $offset)
                ?? self::word($sql, $offset)
                ?? self::symbol($sql, $offset);

            $tokens[] = $token;
            $offset = $token->end;
        }

        return $tokens;
    }

    /**
     * The statements of a script, without their terminating semicolons. A MySQL routine or
     * trigger keeps the rest of the script: its body holds semicolons of its own.
     *
     * @return list<string>
     */
    public static function split(string $sql, Dialect $dialect): array
    {
        $statements = [];
        $start = null;
        $tokens = self::tokenize($sql, $dialect);

        foreach ($tokens as $index => $token) {
            $start ??= $token->start;

            if ($dialect === Dialect::MySql && $token->start === $start && self::opensRoutine($tokens, $index)) {
                $statements[] = rtrim(trim(substr($sql, $start)), ';');
                $start = null;

                break;
            }

            if ($token->isSymbol(';')) {
                $statement = trim(substr($sql, $start, $token->start - $start));
                $start = null;

                if ($statement !== '') {
                    $statements[] = $statement;
                }
            }
        }

        if ($start !== null && ($statement = trim(substr($sql, $start))) !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /** @param list<Token> $tokens */
    private static function opensRoutine(array $tokens, int $index): bool
    {
        if (! $tokens[$index]->is('create')) {
            return false;
        }

        for ($i = $index + 1; $i < count($tokens) && $i < $index + 12; $i++) {
            if ($tokens[$i]->is('trigger', 'procedure', 'function', 'event')) {
                return true;
            }

            if ($tokens[$i]->is('table', 'index', 'view', 'unique', 'temporary') || $tokens[$i]->isSymbol(';')) {
                return false;
            }
        }

        return false;
    }

    /** The offset right after the comment that starts here, if one does. */
    private static function comment(string $sql, int $offset, Dialect $dialect): ?int
    {
        $two = substr($sql, $offset, 2);

        if ($two === '--' || ($dialect === Dialect::MySql && $sql[$offset] === '#')) {
            $end = strpos($sql, "\n", $offset);

            return $end === false ? strlen($sql) : $end + 1;
        }

        if ($two !== '/*') {
            return null;
        }

        $depth = 0;
        $length = strlen($sql);

        for ($i = $offset; $i < $length - 1; $i++) {
            $pair = substr($sql, $i, 2);

            if ($pair === '/*' && ($depth === 0 || $dialect === Dialect::Postgres)) {
                $depth++;
                $i++;
            } elseif ($pair === '*/') {
                $depth--;
                $i++;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $length;
    }

    private static function string(string $sql, int $offset, Dialect $dialect): ?Token
    {
        $char = $sql[$offset];

        if ($char === '$' && $dialect === Dialect::Postgres && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $offset)) {
            $close = strpos($sql, $match[0], $offset + strlen($match[0]));
            $end = $close === false ? strlen($sql) : $close + strlen($match[0]);
            $body = substr($sql, $offset + strlen($match[0]), ($close === false ? strlen($sql) : $close) - $offset - strlen($match[0]));

            return new Token(TokenType::String, $body, $offset, $end);
        }

        $quote = $char;
        $start = $offset;
        $escapes = $dialect->backslashEscapes();

        if (preg_match('/\G(?:[EeNnBbXx]|[Uu]&)\'/', $sql, $match, 0, $offset)) {
            $escapes = $escapes || strtolower($match[0][0]) === 'e';
            $offset += strlen($match[0]) - 1;
            $quote = "'";
        } elseif ($char !== "'" && ! ($char === '"' && $dialect->doubleQuotesStrings())) {
            return null;
        }

        [$value, $end] = self::quoted($sql, $offset, $quote, $escapes);

        return new Token(TokenType::String, $value, $start, $end);
    }

    private static function identifier(string $sql, int $offset, Dialect $dialect): ?Token
    {
        $char = $sql[$offset];

        if ($char === '`' || ($char === '"' && ! $dialect->doubleQuotesStrings())) {
            [$value, $end] = self::quoted($sql, $offset, $char, false);

            return new Token(TokenType::Identifier, $value, $offset, $end);
        }

        if ($char === '[' && $dialect === Dialect::Sqlite) {
            $close = strpos($sql, ']', $offset);
            $end = $close === false ? strlen($sql) : $close + 1;

            return new Token(TokenType::Identifier, substr($sql, $offset + 1, $end - $offset - 2), $offset, $end);
        }

        return null;
    }

    /** @return array{string, int} The unquoted value and the offset after the closing quote. */
    private static function quoted(string $sql, int $offset, string $quote, bool $escapes): array
    {
        $value = '';
        $length = strlen($sql);

        for ($i = $offset + 1; $i < $length; $i++) {
            $char = $sql[$i];

            if ($escapes && $char === '\\' && $i + 1 < $length) {
                $value .= $sql[++$i];
            } elseif ($char === $quote && ($sql[$i + 1] ?? '') === $quote) {
                $value .= $quote;
                $i++;
            } elseif ($char === $quote) {
                return [$value, $i + 1];
            } else {
                $value .= $char;
            }
        }

        return [$value, $length];
    }

    private static function number(string $sql, int $offset): ?Token
    {
        if (! preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $sql, $match, 0, $offset)) {
            return null;
        }

        return new Token(TokenType::Number, $match[0], $offset, $offset + strlen($match[0]));
    }

    private static function parameter(string $sql, int $offset): ?Token
    {
        if (! preg_match('/\G(?:\?|:[A-Za-z_]\w*|\$\d+)/', $sql, $match, 0, $offset)) {
            return null;
        }

        return new Token(TokenType::Parameter, $match[0], $offset, $offset + strlen($match[0]));
    }

    private static function word(string $sql, int $offset): ?Token
    {
        if (! preg_match('/\G[A-Za-z_\x80-\xff][\w$\x80-\xff]*/', $sql, $match, 0, $offset)) {
            return null;
        }

        return new Token(TokenType::Word, strtolower($match[0]), $offset, $offset + strlen($match[0]));
    }

    private static function symbol(string $sql, int $offset): Token
    {
        foreach (self::SYMBOLS as $symbol) {
            if (substr($sql, $offset, strlen($symbol)) === $symbol) {
                return new Token(TokenType::Symbol, $symbol, $offset, $offset + strlen($symbol));
            }
        }

        return new Token(TokenType::Symbol, $sql[$offset], $offset, $offset + 1);
    }
}

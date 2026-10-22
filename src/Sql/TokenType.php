<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

enum TokenType
{
    /** A keyword or an unquoted identifier. */
    case Word;

    /** A quoted identifier: "name", `name` or [name]. */
    case Identifier;

    case String;

    case Number;

    /** A placeholder: ?, :name or $1. */
    case Parameter;

    /** Punctuation and operators. */
    case Symbol;
}

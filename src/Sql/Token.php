<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

final readonly class Token
{
    /**
     * @param  string  $value  Lowercased for words, unquoted for identifiers and strings, as written otherwise.
     */
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $start,
        public int $end,
    ) {}

    public function is(string ...$keywords): bool
    {
        return $this->type === TokenType::Word && in_array($this->value, $keywords, true);
    }

    public function isSymbol(string ...$symbols): bool
    {
        return $this->type === TokenType::Symbol && in_array($this->value, $symbols, true);
    }

    public function isName(): bool
    {
        return $this->type === TokenType::Word || $this->type === TokenType::Identifier;
    }
}

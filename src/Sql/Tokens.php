<?php

declare(strict_types=1);

namespace StrictMigrations\Sql;

/**
 * A cursor over the tokens of one statement, with the few moves a statement classifier
 * needs: matching keyword sequences, reading qualified names, and cutting the tokens
 * into top-level parts.
 */
final class Tokens
{
    private int $position = 0;

    /** @param list<Token> $tokens */
    public function __construct(
        private readonly array $tokens,
        private readonly string $sql,
    ) {}

    public static function of(string $sql, Dialect $dialect): self
    {
        return new self(Tokenizer::tokenize($sql, $dialect), $sql);
    }

    public function peek(int $ahead = 0): ?Token
    {
        return $this->tokens[$this->position + $ahead] ?? null;
    }

    public function next(): ?Token
    {
        return $this->tokens[$this->position++] ?? null;
    }

    public function atEnd(): bool
    {
        return $this->position >= count($this->tokens);
    }

    /** Whether the next tokens are these keywords; an element may list alternatives as "a|b". */
    public function startsWith(string ...$keywords): bool
    {
        foreach (array_values($keywords) as $offset => $alternatives) {
            if (! $this->peek($offset)?->is(...explode('|', $alternatives))) {
                return false;
            }
        }

        return true;
    }

    /** Consumes the keywords when the next tokens are these. */
    public function accept(string ...$keywords): bool
    {
        if (! $this->startsWith(...$keywords)) {
            return false;
        }

        $this->position += count($keywords);

        return true;
    }

    /** Consumes and returns the next token's keyword when it is one of these. */
    public function acceptOne(string ...$keywords): ?string
    {
        $token = $this->peek();

        if ($token === null || ! $token->is(...$keywords)) {
            return null;
        }

        $this->position++;

        return $token->value;
    }

    public function acceptSymbol(string $symbol): bool
    {
        if (! $this->peek()?->isSymbol($symbol)) {
            return false;
        }

        $this->position++;

        return true;
    }

    /** Consumes a name, schema-qualified or not: "public"."orders" reads as public.orders. */
    public function name(): ?string
    {
        $token = $this->peek();

        if ($token === null || ! $token->isName()) {
            return null;
        }

        $this->position++;
        $parts = [$token->value];

        while ($this->peek()?->isSymbol('.') && ($next = $this->peek(1)) !== null && $next->isName()) {
            $this->position += 2;
            $parts[] = $next->value;
        }

        return implode('.', $parts);
    }

    /** Consumes a parenthesized group and returns what is inside it. */
    public function group(): ?self
    {
        if (! $this->peek()?->isSymbol('(')) {
            return null;
        }

        $start = $this->position + 1;
        $depth = 0;

        while (($token = $this->next()) !== null) {
            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);

            if ($depth === 0) {
                return new self(array_slice($this->tokens, $start, $this->position - 1 - $start), $this->sql);
            }
        }

        return new self(array_slice($this->tokens, $start), $this->sql);
    }

    /**
     * The names listed in a parenthesized group, such as the columns of an index. An
     * expression in the list reads as its text.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $group = $this->group();

        if ($group === null) {
            return [];
        }

        return array_map(function (self $part): string {
            $tokens = $part->remaining();

            return count($tokens) === 1 && $tokens[0]->isName() ? $tokens[0]->value : $part->text();
        }, $group->split());
    }

    /**
     * The remaining tokens cut at every top-level separator.
     *
     * @return list<self>
     */
    public function split(string $separator = ','): array
    {
        $parts = [];
        $part = [];
        $depth = 0;

        foreach ($this->remaining() as $token) {
            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);

            if ($depth === 0 && $token->isSymbol($separator)) {
                $parts[] = new self($part, $this->sql);
                $part = [];

                continue;
            }

            $part[] = $token;
        }

        if ($part !== []) {
            $parts[] = new self($part, $this->sql);
        }

        return $parts;
    }

    /** Whether one of these keywords appears among the remaining tokens, at any depth. */
    public function contains(string ...$keywords): bool
    {
        foreach ($this->remaining() as $token) {
            if ($token->is(...$keywords)) {
                return true;
            }
        }

        return false;
    }

    /** Whether one of these keywords appears among the remaining tokens outside parentheses. */
    public function containsAtTop(string ...$keywords): bool
    {
        $depth = 0;

        foreach ($this->remaining() as $token) {
            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);

            if ($depth === 0 && $token->is(...$keywords)) {
                return true;
            }
        }

        return false;
    }

    /** Moves to the first token, at any depth, matching the callback. */
    public function seek(callable $match): bool
    {
        foreach ($this->remaining() as $offset => $token) {
            if ($match($token)) {
                $this->position += $offset;

                return true;
            }
        }

        return false;
    }

    /** Consumes tokens up to, not including, the first top-level one matching the callback. */
    public function until(callable $stop): self
    {
        $start = $this->position;
        $depth = 0;

        while (($token = $this->peek()) !== null) {
            if ($depth === 0 && $stop($token)) {
                break;
            }

            $depth += $token->isSymbol('(') ? 1 : ($token->isSymbol(')') ? -1 : 0);
            $this->position++;
        }

        return new self(array_slice($this->tokens, $start, $this->position - $start), $this->sql);
    }

    /** @return list<Token> */
    public function remaining(): array
    {
        return array_slice($this->tokens, $this->position);
    }

    /** The SQL the remaining tokens were read from. */
    public function text(): string
    {
        $tokens = $this->remaining();

        if ($tokens === []) {
            return '';
        }

        return substr($this->sql, $tokens[0]->start, end($tokens)->end - $tokens[0]->start);
    }
}

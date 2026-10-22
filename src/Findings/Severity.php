<?php

declare(strict_types=1);

namespace StrictMigrations\Findings;

enum Severity: string
{
    /** Refused in block mode. */
    case Unsafe = 'unsafe';

    /** Reported, never refused. */
    case Warning = 'warning';

    /** Shown in verbose mode only. */
    case Note = 'note';

    /** Would have been unsafe or a warning, but the migration says it was checked. */
    case Assured = 'assured';

    public function label(): string
    {
        return match ($this) {
            self::Unsafe => 'UNSAFE',
            self::Warning => 'WARN',
            self::Note => 'NOTE',
            self::Assured => 'ASSURED',
        };
    }
}

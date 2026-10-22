<?php

declare(strict_types=1);

namespace StrictMigrations\Findings;

enum Kind: string
{
    /** The table becomes unavailable, to writes or to everything, while the statement runs. */
    case Lock = 'lock';

    /** The code still running during a rolling deployment breaks. */
    case Deploy = 'deploy';

    /** Rows change inside the migration: long transactions, row locks, partial states. */
    case Data = 'data';

    /** The statement will fail as written. */
    case Error = 'error';

    /** The statement could not be analyzed. */
    case Unread = 'unread';
}

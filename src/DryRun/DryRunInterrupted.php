<?php

declare(strict_types=1);

namespace StrictMigrations\DryRun;

use RuntimeException;

/** Stops a dry run before a statement would reach a database for real. */
final class DryRunInterrupted extends RuntimeException {}

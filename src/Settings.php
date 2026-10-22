<?php

declare(strict_types=1);

namespace StrictMigrations;

use InvalidArgumentException;

/** The configuration, checked once and typed. */
final readonly class Settings
{
    /**
     * @param  list<string>  $disabledIn
     * @param  list<string>|null  $connections
     * @param  list<string>  $disabledRules
     */
    public function __construct(
        public string $mode = 'block',
        public array $disabledIn = ['testing'],
        public ?string $checkAfter = null,
        public bool $checkDown = false,
        public int $smallTableRows = 10_000,
        public string $deployment = 'rolling',
        public int $lockTimeoutSeconds = 10,
        public int $lockTimeoutRetries = 3,
        public int $lockTimeoutRetrySeconds = 10,
        public ?array $connections = null,
        public array $disabledRules = [],
    ) {
        self::expect(in_array($mode, ['block', 'warn', 'off'], true), 'mode', 'must be block, warn or off');
        self::expect($checkAfter === null || preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $checkAfter) === 1, 'check_after', 'must be a migration timestamp such as 2026_10_22_174512');
        self::expect($smallTableRows >= 0, 'small_table_rows', 'must be 0 or more');
        self::expect(in_array($deployment, ['rolling', 'maintenance'], true), 'deployment', 'must be rolling or maintenance');
        self::expect($lockTimeoutSeconds >= 0, 'lock_timeout_seconds', 'must be 0 or more');
        self::expect($lockTimeoutRetries >= 0, 'lock_timeout_retries', 'must be 0 or more');
        self::expect($lockTimeoutRetrySeconds >= 0, 'lock_timeout_retry_seconds', 'must be 0 or more');
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $defaults = new self;

        return new self(
            mode: (string) ($config['mode'] ?? $defaults->mode),
            disabledIn: array_values(array_map('strval', (array) ($config['disabled_in'] ?? $defaults->disabledIn))),
            checkAfter: isset($config['check_after']) && $config['check_after'] !== '' ? (string) $config['check_after'] : null,
            checkDown: (bool) ($config['check_down'] ?? $defaults->checkDown),
            smallTableRows: (int) ($config['small_table_rows'] ?? $defaults->smallTableRows),
            deployment: (string) ($config['deployment'] ?? $defaults->deployment),
            lockTimeoutSeconds: (int) ($config['lock_timeout_seconds'] ?? $defaults->lockTimeoutSeconds),
            lockTimeoutRetries: (int) ($config['lock_timeout_retries'] ?? $defaults->lockTimeoutRetries),
            lockTimeoutRetrySeconds: (int) ($config['lock_timeout_retry_seconds'] ?? $defaults->lockTimeoutRetrySeconds),
            connections: isset($config['connections']) ? array_values(array_map('strval', (array) $config['connections'])) : null,
            disabledRules: array_values(array_map('strval', (array) ($config['disabled_rules'] ?? []))),
        );
    }

    public function blocks(): bool
    {
        return $this->mode === 'block';
    }

    public function off(): bool
    {
        return $this->mode === 'off';
    }

    public function rolling(): bool
    {
        return $this->deployment === 'rolling';
    }

    /** Whether a migration predates check_after, by the timestamp its name starts with. */
    public function predates(string $migration): bool
    {
        if ($this->checkAfter === null || ! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}/', $migration, $match)) {
            return false;
        }

        return strcmp($match[0], $this->checkAfter) <= 0;
    }

    public function watches(?string $connection): bool
    {
        return $this->connections === null || in_array((string) $connection, $this->connections, true);
    }

    private static function expect(bool $valid, string $key, string $problem): void
    {
        if (! $valid) {
            throw new InvalidArgumentException("strict-migrations.{$key} {$problem}.");
        }
    }
}

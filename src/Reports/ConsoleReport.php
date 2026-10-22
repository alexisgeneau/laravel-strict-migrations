<?php

declare(strict_types=1);

namespace StrictMigrations\Reports;

use StrictMigrations\Analysis\Analysis;
use StrictMigrations\Findings\Finding;
use StrictMigrations\Findings\Severity;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleReport
{
    public function __construct(
        private readonly OutputInterface $output,
    ) {}

    /** The line a migration marked #[SafetyAssured] gets instead of an analysis. */
    public function assured(Analysis $analysis): void
    {
        $this->output->writeln(sprintf('  %s %s <fg=gray>%s</>', $this->badge(Severity::Assured), self::escape($analysis->migration), self::escape((string) $analysis->assured)));
    }

    /** The findings worth showing at the output's verbosity, under a header naming the migration; nothing when there are none. */
    public function findings(Analysis $analysis, bool $blocks): void
    {
        $findings = array_values(array_filter(
            $analysis->findings,
            fn (Finding $finding): bool => $finding->severity !== Severity::Note || $this->output->isVerbose(),
        ));

        if ($findings === [] || ($worst = $analysis->worst()) === null) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln(sprintf(
            '  %s <options=bold>%s</>%s',
            $this->badge($worst),
            self::escape($analysis->migration),
            $analysis->server === null ? '' : ' <fg=gray>· '.self::escape($analysis->server->describe()).'</>',
        ));

        foreach ($findings as $finding) {
            $this->output->writeln('');
            $this->finding($finding);
        }

        if ($analysis->refuses()) {
            $this->output->writeln('');
            $this->output->writeln($blocks
                ? [
                    '  <fg=gray>Checked and harmless here? Wrap the statement in Schema::safetyAssured(fn () => ...), or mark the whole migration: new #[SafetyAssured(\'why\')] class extends Migration.</>',
                    '  <fg=gray>STRICT_MIGRATIONS=warn reports without refusing.</>',
                ]
                : '  <fg=gray>Running anyway: strict-migrations.mode is warn.</>');
        }

        $this->output->writeln('');
    }

    private function finding(Finding $finding): void
    {
        [$mark, $color] = match ($finding->severity) {
            Severity::Unsafe => ['⨯', 'red'],
            Severity::Warning => ['!', 'yellow'],
            Severity::Assured => ['✓', 'blue'],
            Severity::Note => ['·', 'gray'],
        };

        $this->output->writeln(sprintf('  <fg=%s;options=bold>%s</> %s', $color, $mark, self::escape($finding->message)));

        foreach (array_filter([$finding->size?->describe(), $finding->statement, $this->repeats($finding)]) as $detail) {
            $this->output->writeln('    <fg=gray>'.self::escape($detail).'</>');
        }

        if ($finding->recipe !== null && $finding->severity !== Severity::Assured) {
            $this->output->writeln('');

            foreach (explode("\n", $finding->recipe) as $line) {
                $this->output->writeln($line === '' ? '' : '    '.self::escape($line));
            }
        }
    }

    private function repeats(Finding $finding): ?string
    {
        return match ($finding->repeats) {
            0 => null,
            1 => 'and 1 more statement like it',
            default => "and {$finding->repeats} more statements like it",
        };
    }

    private function badge(Severity $severity): string
    {
        $background = match ($severity) {
            Severity::Unsafe => 'red',
            Severity::Warning => 'yellow',
            Severity::Assured => 'blue',
            Severity::Note => 'gray',
        };

        return sprintf('<fg=%s;bg=%s;options=bold> %s </>', $severity === Severity::Warning ? 'black' : 'white', $background, $severity->label());
    }

    private static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}

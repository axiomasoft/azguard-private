<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Exceptions\AmbiguousPanelException;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\InvalidPanelIdException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\TenantMismatchException;
use AzGuard\Exceptions\TenantRequiredException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelResolver;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Console\Command;
use Throwable;

/**
 * Input and output rules every AzGuard command shares, for the commands of the package and of its modules.
 *
 * - Exit codes: `0` success, `2` (`INVALID`) an input, panel, tenant or identity error (`InvalidCommandInput` and the
 *   identity exceptions), `1` (`FAILURE`) any other AzGuard or unexpected error. Run the body through `attempt()`.
 * - The panel is picked only by the panel resolver from `--panel`; an unresolved panel is an error, never a guess.
 * - `--force` is required for irreversible changes in production.
 *
 * @phpstan-require-extends Command
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
trait CommandSupport
{
    /**
     * INVALID for input/panel/tenant/identity errors, FAILURE for other AzGuard and unexpected errors.
     *
     * @param  Closure(): int  $body
     */
    protected function attempt(Closure $body): int
    {
        try {
            return $body();
        } catch (InvalidCommandInput|PanelNotResolvedException|UnknownPanelException|AmbiguousPanelException|ConflictingPanelException
            |InvalidPanelIdException|TenantRequiredException|TenantMismatchException|InvalidIdentityException|SubjectNotAcceptedException $error) {
                $this->components->error($error->getMessage());

                return Command::INVALID;
            } catch (AzGuardException $error) {
                $this->components->error(class_basename($error).': '.$error->getMessage());

                return Command::FAILURE;
            } catch (Throwable $error) {
                // A database or framework message may carry connection details; it is shown on request only.
                $this->components->error('The command failed with '.$error::class
                    .($this->output->isVerbose() ? ': '.$error->getMessage() : '. Run it with -v to see the message.'));

                return Command::FAILURE;
            }
    }

    /** The panel of `--panel`, by the rule of the panel resolver; without `--panel` the resolver's default. */
    protected function selectPanel(): Panel
    {
        $panel = $this->stringOption('panel');

        return app(PanelResolver::class)->select(null, [], [], $panel === null ? [] : [$panel]);
    }

    protected function stringOption(string $name): ?string
    {
        if (! $this->hasOption($name)) {
            return null;
        }
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidCommandInput('--'.$name.' needs a value.');
        }

        return $value;
    }

    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidCommandInput('The argument '.$name.' needs a value.');
        }

        return $value;
    }

    /** An ISO-8601 date or date-time of an option; a time without an offset is UTC. */
    protected function dateOption(string $name): ?DateTimeImmutable
    {
        $input = $this->stringOption($name);

        if ($input === null) {
            return null;
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/', $input) !== 1) {
            throw new InvalidCommandInput('--'.$name.' must be an ISO-8601 date or date-time such as 2026-12-31T18:00:00Z.');
        }

        try {
            $date = new DateTimeImmutable($input, new DateTimeZone('UTC'));
        } catch (Exception) {
            $date = null;
        }

        // A day or hour out of range is rolled over by PHP with a warning; such a date is refused, not shifted.
        if ($date === null || DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidCommandInput('--'.$name.' must be an ISO-8601 date or date-time; '.$input.' does not exist.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** Refuses an irreversible change in production unless `--force` is given. */
    protected function confirmIrreversible(string $what, bool $force): void
    {
        if ($this->laravel->environment('production') && ! $force) {
            throw new InvalidCommandInput($what.' cannot be undone: pass --force to run it in production.');
        }
    }

    /** @param array<string, mixed> $data */
    protected function printJson(array $data): void
    {
        $this->line(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}

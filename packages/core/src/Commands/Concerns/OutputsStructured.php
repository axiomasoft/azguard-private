<?php

declare(strict_types=1);

namespace AzGuard\Commands\Concerns;

/**
 * Shared structured-output support for CI-gate commands (guard:doctor, guard:catalog:validate).
 *
 * Adds a `--json` flag and a uniform `{errors, warnings, abilities}` payload, so CI pipelines
 * can parse command output instead of scraping human-readable text.
 *
 * Requires the consuming class to declare a `--json` option and be an
 * `Illuminate\Console\Command` (uses `option()`/`line()`).
 */
trait OutputsStructured
{
    protected function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @param  list<array<string, string>>  $abilities
     */
    protected function renderJsonPayload(array $errors, array $warnings, array $abilities = []): void
    {
        $this->line((string) json_encode(
            value: [
                'errors' => $errors,
                'warnings' => $warnings,
                'abilities' => $abilities,
            ],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
    }
}

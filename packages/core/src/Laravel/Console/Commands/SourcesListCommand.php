<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands;

use AzGuard\Configuration\AzGuardConfig;
use AzGuard\Laravel\Console\Concerns\InteractsWithAzGuard;
use AzGuard\Panels\PanelRecipe;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Sources\SourceManager;
use BackedEnum;
use Illuminate\Console\Command;

/**
 * Lists the names of the source factory (`#[AsSource]` classes and `extend()` creators) with their class, their
 * parameters from `azguard.sources` with secret values redacted, and the panels that use each name. A configured name
 * that nothing registers is listed too. Reads only.
 */
final class SourcesListCommand extends Command
{
    use InteractsWithAzGuard;

    /** @var string */
    protected $signature = 'azguard:sources:list {--json : Print JSON}';

    /** @var string */
    protected $description = 'List the named AzGuard sources, their parameters and the panels that use them';

    public function handle(SourceManager $sources, AzGuardConfig $config, PanelRegistry $registry): int
    {
        return $this->attempt(function () use ($sources, $config, $registry): int {
            $used = [];
            foreach ($registry->all() as $panel) {
                foreach ($registry->recipe($panel->id())->layered(PanelRecipe::PERMISSIONS) as $record) {
                    foreach (is_array($record['value']) ? $record['value'] : [] as $definition) {
                        if (is_string($definition) && ! is_subclass_of($definition, BackedEnum::class)) {
                            $used[$definition][$panel->id()] = true;
                        }
                    }
                }
            }
            $registered = $sources->names();
            $parameters = $config->sources();
            $names = array_unique([...array_keys($registered), ...array_keys($parameters)]);
            sort($names, SORT_STRING);
            $list = array_map(fn (string $name): array => [
                'name' => $name,
                'registered' => array_key_exists($name, $registered),
                'class' => $registered[$name] ?? null,
                'parameters' => $this->redacted($parameters[$name] ?? []),
                'panels' => array_keys($used[$name] ?? []),
            ], $names);

            if ($this->option('json') === true) {
                $this->printJson($list);

                return self::SUCCESS;
            }

            if ($list === []) {
                $this->components->info('No named AzGuard sources are registered or configured.');

                return self::SUCCESS;
            }
            $this->table(['Source', 'Class', 'Parameters', 'Panels'], array_map(static fn (array $source): array => [
                $source['name'],
                $source['registered'] ? ($source['class'] ?? 'extend()') : 'not registered',
                $source['parameters'] === [] ? '' : json_encode($source['parameters'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                implode(', ', $source['panels']),
            ], $list));

            return self::SUCCESS;
        });
    }
}

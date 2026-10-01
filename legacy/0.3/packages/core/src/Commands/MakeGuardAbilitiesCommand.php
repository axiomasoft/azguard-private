<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesGuardNamespaces;
use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MakeGuardAbilitiesCommand extends Command
{
    use ResolvesGuardNamespaces;
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-abilities
        {panel : Panel name (e.g. App)}
        {domain : Domain name (e.g. Documents)}
        {--path=app/Guards}
        {--force : Overwrite existing files}';

    protected $description = 'Create an Abilities DTO based on AbilitiesDto';

    public function handle(): int
    {
        $panel = (string) $this->argument(key: 'panel');
        $domain = (string) $this->argument(key: 'domain');
        $pathOption = (string) $this->option(key: 'path');

        $basePath = $this->guardBasePath(path: $pathOption, panel: $panel);
        $baseNamespace = $this->guardBaseNamespace(path: $pathOption, panel: $panel);
        $abilitiesPath = $this->domainPath(basePath: $basePath, domain: $domain).'/Abilities/'.$domain.'Abilities.php';

        if (! $this->checkFileExists(filePath: $abilitiesPath)) {
            return self::FAILURE;
        }

        File::ensureDirectoryExists(path: dirname(path: $abilitiesPath));

        $stub = File::get(path: __DIR__.'/../../stubs/panel/domain-abilities.stub');
        $replacements = [
            'namespace' => $baseNamespace,
            'domain' => $domain,
            'panelId' => strtolower(string: $panel),
        ];

        foreach ($replacements as $key => $value) {
            $stub = str_replace(search: '{{ '.$key.' }}', replace: $value, subject: $stub);
        }

        File::put(path: $abilitiesPath, contents: $stub);
        $this->info("Abilities created: {$abilitiesPath}");

        return self::SUCCESS;
    }
}

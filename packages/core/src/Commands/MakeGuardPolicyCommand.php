<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesGuardNamespaces;
use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class MakeGuardPolicyCommand extends Command
{
    use ResolvesGuardNamespaces;
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-policy
        {panel : Panel name (e.g. App)}
        {domain : Domain name (e.g. Documents)}
        {--path=app/Guards}
        {--force : Overwrite existing files}';

    protected $description = 'Create a policy stub with GuardPolicy and GateAbility attributes';

    public function handle(): int
    {
        $panel = (string) $this->argument(key: 'panel');
        $domain = (string) $this->argument(key: 'domain');
        $pathOption = (string) $this->option(key: 'path');

        $basePath = $this->guardBasePath(path: $pathOption, panel: $panel);
        $baseNamespace = $this->guardBaseNamespace(path: $pathOption, panel: $panel);
        $policyPath = $this->domainPath(basePath: $basePath, domain: $domain).'/Policies/'.$domain.'Policy.php';

        if (! $this->checkFileExists(filePath: $policyPath)) {
            return self::FAILURE;
        }

        File::ensureDirectoryExists(path: dirname(path: $policyPath));

        $stub = File::get(path: __DIR__.'/../../stubs/panel/domain-policy.stub');
        $replacements = [
            'namespace' => $baseNamespace,
            'domain' => $domain,
            'panelId' => strtolower(string: $panel),
        ];

        foreach ($replacements as $key => $value) {
            $stub = str_replace(search: '{{ '.$key.' }}', replace: $value, subject: $stub);
        }

        File::put(path: $policyPath, contents: $stub);
        $this->info("Policy created: {$policyPath}");

        return self::SUCCESS;
    }
}

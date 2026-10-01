<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesGuardNamespaces;
use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use AzGuard\Scaffold\GuardScaffoldGenerator;
use Illuminate\Console\Command;

final class MakeGuardPanelCommand extends Command
{
    use ResolvesGuardNamespaces;
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-panel
        {panel : Panel name (e.g. App)}
        {domain=Documents : Domain inside the panel}
        {--path=app/Guards : Base path}
        {--role=Admin : Initial role name}
        {--model= : Eloquent domain model FQCN (recommended)}
        {--actor= : Authenticatable actor FQCN (defaults to auth.providers.users.model)}
        {--with-abilities : Also generate an Abilities DTO}
        {--force : Overwrite conflicting generated files}';

    protected $description = 'Scaffold a guard panel with Permissions/Policies/Abilities domain structure';

    public function handle(): int
    {
        $panel = (string) $this->argument(key: 'panel');
        $domain = (string) $this->argument(key: 'domain');
        $pathOption = (string) $this->option(key: 'path');
        $roleName = (string) $this->option(key: 'role');
        $withAbilities = (bool) $this->option(key: 'with-abilities');
        $modelOption = $this->option(key: 'model');
        $actorOption = $this->option(key: 'actor');

        $generator = new GuardScaffoldGenerator(command: $this);

        $plan = $generator->planNewPanel(
            panel: $panel,
            domain: $domain,
            pathOption: $pathOption,
            roleName: $roleName,
            withAbilities: $withAbilities,
            modelOption: is_string($modelOption) && $modelOption !== '' ? $modelOption : null,
            actorOption: is_string($actorOption) && $actorOption !== '' ? $actorOption : null,
        );

        if ($plan === null) {
            return self::FAILURE;
        }

        if (! $generator->writeTargets(targets: $plan['targets'], force: $this->shouldForce())) {
            return self::FAILURE;
        }

        $basePath = $this->guardBasePath(path: $pathOption, panel: $panel);
        $this->info("Panel [{$panel}] created at {$basePath}");

        return self::SUCCESS;
    }
}

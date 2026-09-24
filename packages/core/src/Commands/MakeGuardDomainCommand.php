<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\ResolvesGuardNamespaces;
use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use AzGuard\Scaffold\GuardScaffoldGenerator;
use Illuminate\Console\Command;

final class MakeGuardDomainCommand extends Command
{
    use ResolvesGuardNamespaces;
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-domain
        {panel : Existing panel name}
        {domain : Domain to add inside the panel}
        {--path=app/Guards : Base path used when the panel was created}
        {--model= : Eloquent domain model FQCN}
        {--actor= : Authenticatable actor FQCN (defaults to auth.providers.users.model)}
        {--with-abilities : Also generate an Abilities DTO}
        {--force : Overwrite conflicting generated domain files}';

    protected $description = 'Add a domain (permissions, policy, optional abilities) to an existing guard panel';

    public function handle(): int
    {
        $panel = (string) $this->argument(key: 'panel');
        $domain = (string) $this->argument(key: 'domain');
        $pathOption = (string) $this->option(key: 'path');
        $modelOption = $this->option(key: 'model');
        $actorOption = $this->option(key: 'actor');
        $withAbilities = (bool) $this->option(key: 'with-abilities');

        $generator = new GuardScaffoldGenerator(command: $this);

        $plan = $generator->planAddDomain(
            panel: $panel,
            domain: $domain,
            pathOption: $pathOption,
            withAbilities: $withAbilities,
            modelOption: is_string($modelOption) && $modelOption !== '' ? $modelOption : null,
            actorOption: is_string($actorOption) && $actorOption !== '' ? $actorOption : null,
        );

        if ($plan === null) {
            return self::FAILURE;
        }

        $providerTarget = $generator->permissionProviderTarget(
            providerPath: $plan['providerPath'],
            permissionFqcn: $plan['permissionFqcn'],
        );

        if ($providerTarget === null) {
            return self::FAILURE;
        }

        $targets = [...$plan['targets'], $providerTarget];

        if (! $generator->writeTargets(targets: $targets, force: $this->shouldForce())) {
            return self::FAILURE;
        }

        $this->info("Domain [{$domain}] added to panel [{$panel}].");

        return self::SUCCESS;
    }
}

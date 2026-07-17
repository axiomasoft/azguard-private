<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use AzGuard\Commands\Concerns\SupportsForcefulGeneration;
use AzGuard\Facades\AzGuard;
use AzGuard\PanelProvider;
use AzGuard\Support\Panel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;

final class MakeGuardRoleCommand extends Command
{
    use SupportsForcefulGeneration;

    protected $signature = 'make:guard-role
        {panel? : Panel name (e.g. app)}
        {name? : Role name (e.g. Editor)}
        {--force : Overwrite existing files}';

    protected $description = 'Create a new role for a specific panel';

    public function handle(): int
    {
        $panels = AzGuard::getPanels();

        if (empty($panels)) {
            $this->warn('No registered panels found.');
            $this->line('1. Create a panel: <info>php artisan make:guard-panel</info>');
            $this->line('2. Register it in <info>config/az-guard.php</info>');

            return self::FAILURE;
        }

        $panelIds = array_keys($panels);
        $panelId = $this->argument(key: 'panel');

        if ($panelId === null) {
            $panelId = $this->choice(
                question: 'Which panel should the role belong to?',
                choices: $panelIds,
                default: 0,
            );
        } else {
            $panelId = (string) $panelId;

            if (! in_array($panelId, $panelIds, true)) {
                $this->error("Panel [{$panelId}] is not registered.");

                return self::FAILURE;
            }
        }

        $providerClass = $this->getProviderClassById($panelId);

        if (! $providerClass) {
            $this->error("Provider for panel [{$panelId}] not found in the container.");

            return self::FAILURE;
        }

        $roleName = $this->argument(key: 'name');

        $roleName = $roleName === null ? $this->ask('Role name (e.g. Editor)') : (string) $roleName;

        $roleClass = Str::studly($roleName);

        $reflection = new ReflectionClass($providerClass);
        $panelPath = dirname($reflection->getFileName());
        $panelNamespace = $reflection->getNamespaceName();

        $targetPath = "{$panelPath}/Roles/{$roleClass}Role.php";

        if (! $this->checkFileExists(filePath: $targetPath)) {
            return self::FAILURE;
        }

        File::ensureDirectoryExists("{$panelPath}/Roles");

        $this->generateFile($targetPath, [
            'namespace' => $panelNamespace,
            'name' => $roleClass,
            'resLower' => $panelId.'.base',
        ]);

        $this->info("Role created: {$targetPath}");

        return self::SUCCESS;
    }

    protected function getProviderClassById(string $id): ?string
    {
        foreach (app()->getLoadedProviders() as $providerClass => $bool) {
            if (is_subclass_of($providerClass, PanelProvider::class)) {
                $instance = app()->getProvider($providerClass);

                if ($instance->panel(Panel::make())->getId() === $id) {
                    return $providerClass;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function generateFile(string $path, array $replacements): void
    {
        $stubPath = __DIR__.'/../../stubs/panel/role.stub';
        $content = File::get($stubPath);

        foreach ($replacements as $key => $value) {
            $content = str_replace("{{ $key }}", $value, $content);
        }

        File::put($path, $content);
    }
}

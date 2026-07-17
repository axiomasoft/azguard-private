<?php

declare(strict_types=1);

namespace AzGuard\Commands;

use Illuminate\Console\Command;

/**
 * Guided installer: publish the config, run migrations, and point the developer
 * at the panel-first next steps. The headline onboarding entry point.
 */
final class InstallCommand extends Command
{
    protected $signature = 'guard:install';

    protected $description = 'Install AzGuard: publish config and run migrations';

    public function handle(): int
    {
        $this->components->info('Installing AzGuard…');

        $this->callSilent('vendor:publish', ['--tag' => 'az-guard-config']);
        $this->components->info('Published config to config/az-guard.php');

        if ($this->confirm('Run database migrations now?', default: true)) {
            $this->call('migrate');
        } else {
            $this->components->warn('Skipped migrations — run `php artisan migrate` when ready.');
        }

        $this->newLine();
        $this->components->info('Next steps (panel-first):');
        $this->components->bulletList([
            'Create a panel:     php artisan make:guard-panel',
            'Create a role:      php artisan make:guard-role',
            'Verify the setup:   php artisan guard:doctor',
        ]);

        if ($this->confirm('Star axioma-studio/azguard on GitHub to support the project?', default: false)) {
            $this->components->info('Thank you! ⭐ https://github.com/axioma-studio/azguard');
        }

        $this->components->info('AzGuard installed.');

        return self::SUCCESS;
    }
}

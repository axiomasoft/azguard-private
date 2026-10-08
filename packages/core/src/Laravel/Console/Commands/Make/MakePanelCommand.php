<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\ProviderRegistration;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use Illuminate\Filesystem\Filesystem;

/**
 * The directory of a panel with its provider, listed in `azguard.panels.providers` of the published configuration.
 */
final class MakePanelCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:panel
        {panel : The panel directory, such as Admin}
        {--model= : The subject model of the panel; the model of the users provider by default}
        {--force : Overwrite the provider when it exists}';

    /** @var string */
    protected $description = 'Create the directory and provider of an AzGuard panel';

    private ?string $provider = null;

    protected function stubName(): string
    {
        return 'panel-provider';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $panel = $layout->panelName($this->stringArgument('panel'));
        $place = $layout->panel($panel);
        $class = $panel.'GuardPanelProvider';
        $model = $this->subjectModel();
        $this->provider = $place->fqcn($class);

        return [new GeneratedFile($place->file($class), $stubs->render('panel-provider', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([DatabaseSource::class, PanelBuilder::class, PanelProvider::class, $model]),
            'class' => $class,
            'id' => $layout->panelId($panel),
            'model' => $this->shortName($model),
        ]))];
    }

    protected function written(Layout $layout): void
    {
        $file = $this->laravel->configPath('azguard.php');
        $provider = $this->provider ?? '';

        match ((new ProviderRegistration(new Filesystem))->register($file, $provider)) {
            ProviderRegistration::ADDED => $this->components->info('Listed '.$provider.' in '.$layout->relative($file)),
            ProviderRegistration::LISTED => $this->components->info($provider.' is already listed in '.$layout->relative($file)),
            ProviderRegistration::UNPUBLISHED => $this->components->warn(
                'config/azguard.php is not published, so it was not changed: publish it with `php artisan vendor:publish --tag=azguard-config` and list '
                .$provider.' in azguard.panels.providers.',
            ),
            default => $this->components->warn(
                $layout->relative($file).' has no azguard.panels.providers list that could be read: list '.$provider.' in it.',
            ),
        };
    }

    private function subjectModel(): string
    {
        $option = $this->stringOption('model');
        $model = $option ?? $this->laravel->make('config')->get('auth.providers.users.model');

        if (! is_string($model) || preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*\z/', $model) !== 1) {
            return $option === null ? 'App\\Models\\User' : throw new InvalidCommandInput('--model must be the class of a model, such as App\\Models\\User.');
        }

        return ltrim($model, '\\');
    }

    private function shortName(string $class): string
    {
        return ($position = strrpos($class, '\\')) === false ? $class : substr($class, $position + 1);
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Console\Make;

use AzGuard\DevTools\Internal\Scaffold\Layout;
use AzGuard\DevTools\Internal\Scaffold\StubStore;
use AzGuard\Laravel\Console\InvalidCommandInput;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\Database\DatabaseSource;
use Packman\Attributes\Internal;
use Packman\DevTools\Scaffold\ProviderRegistration;

/**
 * The directory of a panel with its provider, listed in `azguard.panels.providers` of the published configuration.
 */
#[Internal]
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

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $panel = $layout->panelName($this->stringArgument('panel'));
        $place = $layout->panel($panel);
        $class = $panel.'GuardPanelProvider';
        $model = $this->subjectModel();
        $this->provider = $place->fqcn($class);

        return [$this->generated($layout, $place->file($class), $stubs->render('panel-provider', [
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

        if (! $this->files->isFile($file)) {
            $this->components->warn(
                'config/azguard.php is not published, so it was not changed: publish it with `php artisan vendor:publish --tag=azguard-config` and list '
                .$provider.' in azguard.panels.providers.',
            );

            return;
        }

        $source = $this->files->get($file);
        $edited = ProviderRegistration::registerInPhpArray($source, ['panels', 'providers'], $provider);

        if ($edited === null) {
            $this->components->warn(
                $layout->relative($file).' has no azguard.panels.providers list that could be read: list '.$provider.' in it.',
            );

            return;
        }

        if ($edited === $source) {
            $this->components->info($provider.' is already listed in '.$layout->relative($file));

            return;
        }

        $this->files->put($file, $edited);
        $this->components->info('Listed '.$provider.' in '.$layout->relative($file));
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

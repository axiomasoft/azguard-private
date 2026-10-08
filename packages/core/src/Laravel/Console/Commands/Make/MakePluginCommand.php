<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Plugins\BasePlugin;
use AzGuard\Plugins\PluginContext;
use Illuminate\Support\Str;

/**
 * A plugin in `{Panel}/Plugins/` or `Shared/Plugins/`: its own typed named factory, no options array.
 */
final class MakePluginCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:plugin
        {name : The plugin, such as AuditTrail}
        {--panel= : Put it in this panel}
        {--shared : Put it where panels share things}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create a plugin of an AzGuard panel';

    protected function stubName(): string
    {
        return 'plugin';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $place = $this->panelOrShared($layout, 'Plugins', $this->stringOption('panel'), (bool) $this->option('shared'));
        $class = $layout->className($this->stringArgument('name'), 'Plugin');

        return [new GeneratedFile($place->file($class), $stubs->render('plugin', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([BasePlugin::class, PanelBuilder::class, PluginContext::class]),
            'class' => $class,
            'id' => 'app/'.Str::kebab(substr($class, 0, -6)),
        ]))];
    }
}

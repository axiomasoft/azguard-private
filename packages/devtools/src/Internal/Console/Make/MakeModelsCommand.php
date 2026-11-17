<?php

declare(strict_types=1);

namespace AzGuard\DevTools\Internal\Console\Make;

use AzGuard\AzGuard;
use AzGuard\DevTools\Internal\Scaffold\GeneratedFile;
use AzGuard\DevTools\Internal\Scaffold\Layout;
use AzGuard\DevTools\Internal\Scaffold\StubStore;
use AzGuard\Laravel\Console\InvalidCommandInput;
use AzGuard\Schema\Field;
use AzGuard\Storage\Models\PermissionGrant;
use AzGuard\Storage\Models\RoleGrant;
use AzGuard\Storage\StorageDescriptor;
use Illuminate\Support\Str;

/**
 * The grant models of a panel, `{Panel}/Models/`, as subclasses of the base ones that declare the host fields, and the
 * migration that adds their columns. The panel takes them with `DatabaseSource::make()->models(...)`.
 */
final class MakeModelsCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:models
        {panel : The panel directory, such as Admin}
        {--storage=default : The storage whose tables get the columns}
        {--force : Overwrite the files when they exist}';

    /** @var string */
    protected $description = 'Create the grant models of a panel and the migration of their columns';

    protected function stubName(): string
    {
        return 'panel-models';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $panel = $layout->panelName($this->stringArgument('panel'));
        $place = $layout->existingPanel($panel)->in('Models');
        $storage = $this->stringOption('storage') ?? 'default';

        $known = array_map(static fn (StorageDescriptor $descriptor): string => $descriptor->id, $this->laravel->make(AzGuard::class)->storages());

        if (! in_array($storage, $known, true)) {
            throw new InvalidCommandInput('Unknown storage '.$storage.': configure it under azguard.storages first.');
        }
        $files = [];
        foreach (['RoleGrant' => RoleGrant::class, 'PermissionGrant' => PermissionGrant::class] as $name => $base) {
            $files[] = new GeneratedFile($place->file($panel.$name), $stubs->render('panel-models', [
                'namespace' => $place->namespace,
                'imports' => StubStore::imports([$base, Field::class]),
                'class' => $panel.$name,
                'base' => $name,
            ]));
        }
        $suffix = '_add_'.Str::snake($panel).'_columns_to_azguard_tables.php';
        $directory = $this->laravel->databasePath('migrations');
        $existing = $this->files->glob($directory.'/*'.$suffix);
        $files[] = new GeneratedFile(is_string($existing[0] ?? null) ? $existing[0] : $directory.'/'.date('Y_m_d_His').$suffix, $stubs->render('models-migration', [
            'storage' => $storage,
            'panel' => $place->namespace,
        ]));

        return $files;
    }

    protected function written(Layout $layout): void
    {
        $panel = $layout->panelName($this->stringArgument('panel'));
        $this->components->info('Attach them in the provider of '.$panel.': DatabaseSource::make()->models(roleGrant: '.$panel.'RoleGrant::class, permissionGrant: '.$panel.'PermissionGrant::class), and declare the host fields in azguardFields().');
    }
}

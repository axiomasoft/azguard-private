<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * A role of a panel, `Roles/{Name}Role.php`, with its key in `#[Role]`: the key is stored with every grant of the
 * role, so it is written down and never taken from the class name.
 */
final class MakeRoleCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:role
        {panel : The panel directory, such as Admin}
        {name : The role, such as Manager}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create a role of a panel with an explicit key';

    protected function stubName(): string
    {
        return 'role';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $place = $layout->existingPanel($layout->panelName($this->stringArgument('panel')))->in($layout->folder('roles'));
        $class = $layout->className($this->stringArgument('name'), 'Role');
        $base = substr($class, 0, -4);
        $key = Str::kebab($base);

        if (! PermissionGrammar::isRoleKey($key)) {
            throw new InvalidCommandInput('The role key "'.$key.'" of '.$class.' is not valid: it is lowercase letters, digits and hyphens, at most 64 characters.');
        }

        return [new GeneratedFile($place->file($class), $stubs->render('role', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports([BaseRole::class, Role::class, UnitEnum::class]),
            'key' => $key,
            'label' => Str::headline($base),
            'class' => $class,
        ]))];
    }
}

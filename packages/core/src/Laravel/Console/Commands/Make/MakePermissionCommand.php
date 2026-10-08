<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Concerns\SubjectAccess;
use AzGuard\Kernel\Grammar\PermissionGrammar;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Laravel\Console\Concerns\InvalidCommandInput;
use AzGuard\Laravel\Console\Scaffold\EnumSource;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\PolicyFile;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use AzGuard\Permissions\Describe;
use AzGuard\Permissions\RequiresGrant;
use AzGuard\Permissions\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The permission enum of a group, `Permissions/{Group}/{Name}Permission.php`, and with `--policy` and `--abilities` the
 * parallel `Policies/{Group}/{Name}Policy.php` and `Abilities/{Group}/{Name}Abilities.php`.
 */
final class MakePermissionCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:permission
        {panel : The panel directory, such as Admin}
        {group : The group of the permissions, such as Orders or Sales/Orders}
        {--model= : The model of the group, for #[Resource]}
        {--policy : Also create the policy of the group}
        {--abilities : Also create the abilities DTO of the group}
        {--force : Overwrite the files when they exist}';

    /** @var string */
    protected $description = 'Create the permission enum of a group of a panel, with its policy and abilities on request';

    protected function stubName(): string
    {
        return 'permission';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $panel = $layout->existingPanel($layout->panelName($this->stringArgument('panel')));
        $group = $layout->group($this->stringArgument('group'));
        $relative = implode('/', $group);
        $name = Layout::nameOf($group[array_key_last($group)]);
        $model = $this->modelOption();
        $enum = $name.'Permission';
        $permissions = $panel->in($layout->folder('permissions').'/'.$relative);
        $key = implode('.', array_map(Str::kebab(...), $group));

        if (! PermissionGrammar::isLocalKey($key.'.view-any')) {
            throw new InvalidCommandInput('The group '.implode('/', $group).' gives the permission key "'.$key.'", which is not valid: keys are lowercase segments of letters, digits, hyphens and underscores.');
        }

        $contents = $stubs->render('permission', [
            'namespace' => $permissions->namespace,
            'imports' => StubStore::imports([Describe::class, RequiresGrant::class, Resource::class, ...($model === null ? [] : [$model])]),
            'label' => Str::headline($group[array_key_last($group)]),
            'model' => $model === null ? '' : ', model: '.substr($model, (int) strrpos($model, '\\') + 1).'::class',
            'class' => $enum,
            'key' => $key,
        ]);
        $cases = EnumSource::read($contents)->cases ?? [];

        if ($cases === []) {
            throw new InvalidCommandInput('The permission stub declares no string-backed enum with cases.');
        }
        $files = [new GeneratedFile($permissions->file($enum), $contents)];

        if ($this->option('policy')) {
            $policy = $panel->in($layout->folder('policies').'/'.$relative);
            $files[] = new GeneratedFile($policy->file($name.'Policy'), PolicyFile::render($stubs, $policy, $name.'Policy', $permissions->fqcn($enum), $cases, false));
        }

        if ($this->option('abilities')) {
            $abilities = $panel->in($layout->folder('abilities').'/'.$relative);
            $files[] = new GeneratedFile($abilities->file($name.'Abilities'), $this->abilities($stubs, $abilities->namespace, $name.'Abilities', $permissions->fqcn($enum), $enum, $cases, Str::headline($group[array_key_last($group)])));
        }

        return $files;
    }

    /** @return class-string|null */
    private function modelOption(): ?string
    {
        $model = $this->stringOption('model');

        if ($model === null) {
            return null;
        }

        if (preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*\z/', $model) !== 1) {
            throw new InvalidCommandInput('--model must be the class of a model, such as App\\Models\\Order.');
        }

        /** @var class-string $class */
        $class = ltrim($model, '\\');

        return $class;
    }

    /**
     * @param  list<string>  $cases
     */
    private function abilities(StubStore $stubs, string $namespace, string $class, string $enumClass, string $enum, array $cases, string $label): string
    {
        $properties = $arguments = [];
        foreach ($cases as $case) {
            $property = lcfirst($case);
            $properties[] = '        public bool $'.$property.',';
            $arguments[] = '            '.$property.': $access->hasPermission('.$enum.'::'.$case.', $on),';
        }

        return $stubs->render('abilities', [
            'namespace' => $namespace,
            'imports' => StubStore::imports([$enumClass, SubjectAccess::class, AssignmentScopeRef::class, Model::class]),
            'label' => $label,
            'class' => $class,
            'properties' => implode("\n", $properties),
            'arguments' => implode("\n", $arguments),
        ]);
    }
}

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
use AzGuard\Permissions\PolicyOnly;
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
        {--authority=grants : Who decides the permissions: grants (a grant is required) or policy (the policy alone decides; its file is always created)}
        {--case=* : A case as Name=local.key, repeatable; when given, the cases replace the five CRUD ones}
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
        $policyOnly = $this->policyOnly();
        $custom = $this->customCases();
        $enum = $name.'Permission';
        $permissions = $panel->in($layout->folder('permissions').'/'.$relative);
        $key = implode('.', array_map(Str::kebab(...), $group));

        if (! PermissionGrammar::isLocalKey($key.'.view_any')) {
            throw new InvalidCommandInput('The group '.implode('/', $group).' gives the permission key "'.$key.'", which is not valid: keys are lowercase segments of letters, digits, hyphens and underscores.');
        }

        $contents = $stubs->render('permission', [
            'namespace' => $permissions->namespace,
            'imports' => StubStore::imports([Describe::class, $policyOnly ? PolicyOnly::class : RequiresGrant::class, Resource::class, ...($model === null ? [] : [$model])]),
            'label' => Str::headline($group[array_key_last($group)]),
            'model' => $model === null ? '' : ', model: '.substr($model, (int) strrpos($model, '\\') + 1).'::class',
            'class' => $enum,
            'authority' => $policyOnly ? '#[PolicyOnly]' : '#[RequiresGrant]',
            'cases' => $this->casesSource($custom ?? self::crud($key)),
        ]);
        $source = EnumSource::read($contents);
        $cases = $source->cases ?? [];

        if ($source === null || $cases === []) {
            throw new InvalidCommandInput('The permission stub declares no string-backed enum with cases.');
        }

        if ($custom !== null && $cases !== array_keys($custom)) {
            throw new InvalidCommandInput('The permission stub does not use {{ cases }}, so --case would be ignored: publish the stubs again with azguard:stubs --force.');
        }

        if ($source->policyOnly !== $policyOnly) {
            throw new InvalidCommandInput('The permission stub does not declare the authority with {{ authority }}, so --authority would be ignored: publish the stubs again with azguard:stubs --force.');
        }
        $files = [new GeneratedFile($permissions->file($enum), $contents)];

        // A permission that only a policy decides has no grant behind it, so its policy is not optional.
        if ($this->option('policy') || $policyOnly) {
            $policy = $panel->in($layout->folder('policies').'/'.$relative);
            $files[] = new GeneratedFile($policy->file($name.'Policy'), PolicyFile::render($stubs, $policy, $name.'Policy', $permissions->fqcn($enum), $cases, false, ! $policyOnly));
        }

        if ($this->option('abilities')) {
            $abilities = $panel->in($layout->folder('abilities').'/'.$relative);
            $files[] = new GeneratedFile($abilities->file($name.'Abilities'), $this->abilities($stubs, $abilities->namespace, $name.'Abilities', $permissions->fqcn($enum), $enum, $cases, Str::headline($group[array_key_last($group)])));
        }

        return $files;
    }

    private function policyOnly(): bool
    {
        return match ($this->stringOption('authority') ?? 'grants') {
            'grants' => false,
            'policy' => true,
            default => throw new InvalidCommandInput('--authority is grants or policy.'),
        };
    }

    /**
     * The cases of `--case=Name=local.key`, by name; null when the option is not given.
     *
     * @return array<string, array{key: string, label: string}>|null
     */
    private function customCases(): ?array
    {
        $given = array_values(array_filter((array) $this->option('case'), is_string(...)));

        if ($given === []) {
            return null;
        }
        $cases = $keys = [];

        foreach ($given as $case) {
            [$name, $key] = array_pad(explode('=', $case, 2), 2, '');

            if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $name) !== 1 || ! PermissionGrammar::isLocalKey($key)) {
                throw new InvalidCommandInput('--case is Name=local.key, such as ViewAny=orders.view_any: a case name of letters and digits and a valid permission key, got "'.$case.'".');
            }

            if (isset($cases[$name]) || isset($keys[$key])) {
                throw new InvalidCommandInput('--case gives the name "'.$name.'" or the key "'.$key.'" twice.');
            }
            $cases[$name] = ['key' => $key, 'label' => Str::headline($name)];
            $keys[$key] = true;
        }

        return $cases;
    }

    /**
     * @return array<string, array{key: string, label: string}>
     */
    private static function crud(string $key): array
    {
        return [
            'ViewAny' => ['key' => $key.'.view_any', 'label' => 'View list'],
            'View' => ['key' => $key.'.view', 'label' => 'View'],
            'Create' => ['key' => $key.'.create', 'label' => 'Create'],
            'Update' => ['key' => $key.'.update', 'label' => 'Update'],
            'Delete' => ['key' => $key.'.delete', 'label' => 'Delete'],
        ];
    }

    /**
     * @param  array<string, array{key: string, label: string}>  $cases
     */
    private function casesSource(array $cases): string
    {
        $lines = [];

        foreach ($cases as $name => ['key' => $key, 'label' => $label]) {
            $lines[] = "    #[Describe('".addcslashes($label, "'\\")."')]\n    case ".$name." = '".$key."';";
        }

        return implode("\n\n", $lines);
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

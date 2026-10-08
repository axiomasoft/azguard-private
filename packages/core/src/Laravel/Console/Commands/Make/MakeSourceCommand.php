<?php

declare(strict_types=1);

namespace AzGuard\Laravel\Console\Commands\Make;

use AzGuard\Attributes\AsSource;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Contracts\Sources\ProvidesRoles;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Laravel\Console\Scaffold\GeneratedFile;
use AzGuard\Laravel\Console\Scaffold\Layout;
use AzGuard\Laravel\Console\Scaffold\StubStore;
use AzGuard\Panels\Panel;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Roles\BaseRole;
use Illuminate\Support\Str;

/**
 * A source in `{Panel}/Sources/` or `Shared/Sources/`, with `#[AsSource]` and the capabilities asked for.
 */
final class MakeSourceCommand extends MakeCommand
{
    /** @var string */
    protected $signature = 'azguard:make:source
        {name : The source, such as Ldap}
        {--panel= : Put it in this panel}
        {--shared : Put it where panels share things}
        {--grants : Give grants (ProvidesGrants)}
        {--permissions : Give permissions (ProvidesPermissions)}
        {--roles : Give roles (ProvidesRoles)}
        {--policies : Give policy bindings (ProvidesPolicies)}
        {--force : Overwrite the file when it exists}';

    /** @var string */
    protected $description = 'Create a source of an AzGuard panel';

    protected function stubName(): string
    {
        return 'source';
    }

    protected function plan(Layout $layout, StubStore $stubs): array
    {
        $place = $this->panelOrShared($layout, 'Sources', $this->stringOption('panel'), (bool) $this->option('shared'));
        $class = $layout->className($this->stringArgument('name'), 'Source');
        $capabilities = array_filter([
            'grants' => [ProvidesGrants::class, [Grant::class, AccessScope::class, SubjectRef::class, EvaluationContext::class, Volatility::class]],
            'permissions' => [ProvidesPermissions::class, [PermissionDefinition::class, Panel::class, TenantRef::class]],
            'roles' => [ProvidesRoles::class, [BaseRole::class, Panel::class]],
            'policies' => [ProvidesPolicies::class, [PolicyBinding::class, Panel::class]],
        ], fn (string $option): bool => (bool) $this->option($option), ARRAY_FILTER_USE_KEY);

        $imports = [AsSource::class];
        $interfaces = [];
        $methods = '';
        foreach ($capabilities as $option => [$interface, $uses]) {
            $interfaces[] = substr($interface, (int) strrpos($interface, '\\') + 1);
            $imports = [...$imports, $interface, ...$uses];
            $methods .= "\n\n".trim($stubs->render('source-'.$option, []), "\n");
        }

        if ($interfaces === []) {
            $interfaces = ['Source'];
            $imports[] = Source::class;
        }

        return [new GeneratedFile($place->file($class), $stubs->render('source', [
            'namespace' => $place->namespace,
            'imports' => StubStore::imports($imports),
            'name' => Str::kebab(substr($class, 0, -6)),
            'class' => $class,
            'implements' => implode(', ', $this->alphabetical($interfaces)),
            'methods' => $methods,
        ]))];
    }

    /**
     * Pint keeps the interfaces of a class in alphabetical order.
     *
     * @param  list<string>  $interfaces
     * @return list<string>
     */
    private function alphabetical(array $interfaces): array
    {
        sort($interfaces, SORT_STRING | SORT_FLAG_CASE);

        return $interfaces;
    }
}

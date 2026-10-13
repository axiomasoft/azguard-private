<?php

declare(strict_types=1);

namespace AzGuard\Facades;

use AzGuard\Attributes\Api;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\StorageDescriptor;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use UnitEnum;

/**
 * Entry point of the package: the modules bring or extend panels here, the application reaches a panel as a whole
 * and asks for a permission. Every method forwards to the entry class; this block is generated from it by
 * `composer api:manifest` and never edited by hand (`composer api:check` fails on a difference).
 *
 * @method static mixed actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure(): mixed $callback)
 * @method static void authorize(Model|Authenticatable|SubjectRef $subject, UnitEnum|string $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null)
 * @method static bool check(Model|Authenticatable|SubjectRef $subject, UnitEnum|string $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null)
 * @method static void configurePanel(string $id, Closure(PanelBuilder): mixed $callback)
 * @method static void configurePanels(Closure(PanelBuilder): mixed $callback)
 * @method static ?Panel currentPanel()
 * @method static ?AssignmentScopeRef currentScope()
 * @method static \AzGuard\Testing\AzGuardFake fake()
 * @method static PanelAccess panel(string $id)
 * @method static array<string, Panel> panels()
 * @method static void registerPanel(class-string<PanelProvider> $provider)
 * @method static SourceManager sources()
 * @method static StorageDescriptor storage(string $id = 'default')
 * @method static list<StorageDescriptor> storages()
 * @method static mixed withinScope(Model|AssignmentScopeRef $context, Closure(): mixed $callback)
 *
 * @see \AzGuard\AzGuard
 */
#[Api(since: '1.0')]
final class AzGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'azguard';
    }
}

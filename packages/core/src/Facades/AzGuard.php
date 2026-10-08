<?php

declare(strict_types=1);

namespace AzGuard\Facades;

use AzGuard\AzGuardManager;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Sources\SourceManager;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use UnitEnum;

/**
 * Entry point of the package: the modules bring or extend panels here, the application reaches a panel as a whole
 * and asks for a permission.
 *
 * `$subject` is a model or a {@see SubjectRef}. `check()` and `authorize()` pick the panel by the rule of the panel
 * resolver, the same as the model trait; `panel()` is the only other way in and takes the id of a panel.
 *
 * @method static PanelAccess panel(string $id)
 * @method static void registerPanel(class-string<PanelProvider> $provider)
 * @method static void configurePanel(string $id, Closure(PanelBuilder): mixed $callback)
 * @method static void configurePanels(Closure(PanelBuilder): mixed $callback)
 * @method static array<string, Panel> panels()
 * @method static Panel|null currentPanel()
 * @method static SourceManager sources()
 * @method static bool check(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null)
 * @method static void authorize(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null)
 * @method static mixed withinScope(Model|AssignmentScopeRef $context, Closure $callback)
 * @method static AssignmentScopeRef|null currentScope()
 * @method static mixed actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure $callback)
 *
 * @see AzGuardManager
 */
final class AzGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'azguard';
    }
}

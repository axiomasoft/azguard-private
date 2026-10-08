<?php

declare(strict_types=1);

namespace AzGuard;

use AzGuard\Changes\ActingActor;
use AzGuard\Concerns\ScopedPanelAccess;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Contracts\Panels\PanelRegistry;
use AzGuard\Exceptions\AssignmentScopeNotAcceptedException;
use AzGuard\Exceptions\InvalidIdentityException;
use AzGuard\Exceptions\PanelNotResolvedException;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\UnknownPanelException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Panels\PanelResolver;
use AzGuard\Scopes\CurrentContext;
use AzGuard\Scopes\ModelIdentity;
use AzGuard\Scopes\WithinContext;
use AzGuard\Sources\SourceManager;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnitEnum;

/**
 * Root of the AzGuard facade.
 *
 * The manager keeps no panel state and chooses no panel: each call forwards to the registry, the engine or the panel
 * of the current request. The rule that picks a panel for a check stays in the resolver.
 */
final class AzGuardManager
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  class-string<PanelProvider>  $provider
     */
    public function registerPanel(string $provider): void
    {
        $this->app->make(PanelRegistry::class)->register($provider);
    }

    /**
     * Adds to one panel on behalf of its provider. An id nobody registered is reported when the panels are compiled.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     */
    public function configurePanel(string $id, Closure $callback): void
    {
        $this->app->make(PanelRegistry::class)->configure($id, $callback);
    }

    /**
     * Applies one adjustment to every panel, below each panel's own provider.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     */
    public function configurePanels(Closure $callback): void
    {
        $this->app->make(PanelRegistry::class)->configureAll($callback);
    }

    /**
     * @return array<string, Panel> panels by id, in registration order
     */
    public function panels(): array
    {
        return $this->app->make(PanelRegistry::class)->all();
    }

    /**
     * The panel of the current request, or null when the request has none. This reads the panel; it does not pick one.
     */
    public function currentPanel(): ?Panel
    {
        return $this->app->make(CurrentPanel::class)->get();
    }

    /**
     * The source factory. Each `make()` returns a new instance.
     */
    public function sources(): SourceManager
    {
        return $this->app->make(SourceManager::class);
    }

    /**
     * The access to one panel as a whole. The panel is the one registered under the id; nothing else selects it.
     *
     * @throws UnknownPanelException
     */
    public function panel(string $id): PanelAccess
    {
        return new ScopedPanelAccess($this->app->make(PanelRegistry::class)->get($id));
    }

    /**
     * Whether the subject holds the permission. The panel is picked by the rule of the panel resolver from the
     * subject, the permission, `$guard` (the id of a panel) and the panel of the request, as for the model trait.
     *
     * @throws SubjectNotAcceptedException when the subject is not a model or a reference the panel accepts
     */
    public function check(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): bool
    {
        return $this->subjectAccess($subject, $permission, $guard)->hasPermission($permission, $on);
    }

    /**
     * As `check()`, and an `AuthorizationException` when the permission is not held. The exception carries the code of
     * the reason in `response()->code()` and no explanation of the decision.
     *
     * @throws AuthorizationException
     */
    public function authorize(mixed $subject, string|UnitEnum $permission, Model|AssignmentScopeRef|null $on = null, ?string $guard = null): void
    {
        $decision = $this->subjectAccess($subject, $permission, $guard)->decide($permission, $on);

        if (! $decision->allowed()) {
            Response::deny(code: $decision->reason->value)->authorize();
        }
    }

    /**
     * Runs the callback with the context as the current one of the panel of the request, or of the default panel.
     * The previous context comes back afterwards, also when the callback throws.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws PanelNotResolvedException when the request has no panel and none is the default
     */
    public function withinScope(Model|AssignmentScopeRef $context, Closure $callback): mixed
    {
        $panel = $this->scopePanel() ?? throw new PanelNotResolvedException('No current panel and no default panel: nothing tells which panel the scope belongs to.');
        $context = $context instanceof Model
            ? ModelIdentity::context($panel, $context) ?? throw new AssignmentScopeNotAcceptedException(
                $context::class.' is not an assignment scope type of panel '.$panel->id().'.',
            )
            : $context;
        $tenant = $this->app->make(CurrentContext::class)->get($panel)->tenant ?? TenantRef::global();

        return $this->app->make(WithinContext::class)->run($panel, AccessScope::in($tenant, $context), $callback);
    }

    /**
     * The assignment scope the callback of `withinScope()` runs in, or null outside one.
     */
    public function currentScope(): ?AssignmentScopeRef
    {
        $panel = $this->scopePanel();
        $context = $panel === null ? null : $this->app->make(CurrentContext::class)->get($panel)?->context;

        return $context === null || $context->isGlobal() ? null : $context;
    }

    /**
     * Runs the callback with the actor of the changes it makes. A string is a system actor with that reason.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @throws InvalidArgumentException when the string is empty or the actor is not a model
     */
    public function actingAs(Model|Authenticatable|SubjectRef|string $actor, Closure $callback): mixed
    {
        return $this->app->make(ActingActor::class)->run($this->actorOf($actor), $callback);
    }

    /**
     * @throws InvalidIdentityException
     */
    private function actorOf(Model|Authenticatable|SubjectRef|string $actor): ActorRef
    {
        if (is_string($actor)) {
            return trim($actor) === '' ? throw new InvalidArgumentException('A system actor needs a reason: pass a model, a subject reference or a non-empty string.') : ActorRef::system($actor);
        }

        if ($actor instanceof SubjectRef) {
            return ActorRef::of($actor->type(), $actor->id());
        }

        if (! $actor instanceof Model) {
            throw new InvalidArgumentException($actor::class.' is not an Eloquent model: pass a model, a subject reference or a string reason.');
        }

        return ActorRef::of($actor->getMorphClass(), ModelIdentity::key($actor));
    }

    /**
     * The panel for a check: the one rule of the resolver, so a check by the facade and by the model agree.
     *
     * @throws SubjectNotAcceptedException
     */
    private function subjectAccess(mixed $subject, string|UnitEnum $permission, ?string $guard): SubjectAccess
    {
        if (! $subject instanceof Model && ! $subject instanceof SubjectRef) {
            throw new SubjectNotAcceptedException(get_debug_type($subject).' is not a subject: pass an Eloquent model or a subject reference.');
        }
        $panel = $this->app->make(PanelResolver::class)->select($subject, [$permission], [], $guard === null ? [] : [$guard]);

        return new SubjectAccess($panel, $subject);
    }

    /** The panel of the request, otherwise the default panel. */
    private function scopePanel(): ?Panel
    {
        $current = $this->currentPanel();

        if ($current !== null) {
            return $current;
        }
        foreach ($this->panels() as $panel) {
            if ($panel->isDefault()) {
                return $panel;
            }
        }

        return null;
    }
}

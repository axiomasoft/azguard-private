<?php

declare(strict_types=1);

namespace AzGuard\Filament\Authorization;

use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Laravel\Gate\GateBridge;
use Closure;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnitEnum;

use function Filament\get_authorization_response;

/**
 * Decides the resources, relation managers, pages and widgets of a Filament panel in the guard panel of its plugin, by
 * the class that asks, never by a model.
 *
 * A resource asks for `{slug}.{ability}`, a page for `pages.{slug}`, a widget for `widgets.{name}`; a record goes to the
 * guard panel as the resource of the decision, so its scope resolvers find the context. With `enforce()` every failure
 * of an input is a refusal: no user, a user who holds no roles or whom the guard panel does not accept, a permission
 * without a definition. Without `enforce()` the permissions that the guard panel defines are decided the same way and
 * the rest is left to Filament. A class excluded by the plugin, and every class of a Filament panel without the plugin,
 * is left to Filament.
 *
 * `visible()` limits a query of a resource to the records the user may view, before any count or page is taken.
 * `before()` is the `Gate::before` hook of the package: inside a Filament panel that enforces, it refuses an ability of
 * Filament on a model that has neither a policy nor a Gate ability, the check that Filament would otherwise allow. It
 * never allows anything.
 *
 * @api
 */
final class FilamentGate
{
    /**
     * @param  class-string<FilamentResource>  $resource
     *
     * @throws InvalidConfigurationException when the slug of the resource is not a permission segment
     */
    public static function resource(string $resource, string|UnitEnum $action, ?Model $record = null): Response
    {
        $filament = static function () use ($resource, $action, $record): Response {
            if ($resource::shouldSkipAuthorization()) {
                return Response::allow();
            }

            return get_authorization_response($action, $record ?? $resource::getModel(), $resource::shouldCheckPolicyExistence());
        };
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('resources', $resource)) {
            return $filament();
        }

        return self::decide($context, $context->resourceKey($resource, $action), $record, $filament);
    }

    /**
     * A relation manager is decided as the resource it names: its related resource or `$azguardResource`.
     *
     * @param  class-string<RelationManager>  $relationManager
     * @param  (Closure(): Response)|null  $otherwise  what Filament decides when AzGuard does not
     */
    public static function relationManager(string $relationManager, string|UnitEnum $action, ?Model $record = null, ?Closure $otherwise = null): Response
    {
        $resource = FilamentContext::relationResource($relationManager);

        if ($resource !== null) {
            return self::resource($resource, $action, $record);
        }
        $context = FilamentContext::current();

        return $context?->enforced() ? self::refused('azguard.filament.resource') : ($otherwise ?? Response::allow(...))();
    }

    /**
     * @param  class-string<Page>  $page
     * @param  (Closure(): Response)|null  $otherwise  what Filament decides when AzGuard does not
     *
     * @throws InvalidConfigurationException when the slug of the page is not a permission segment
     */
    public static function page(string $page, ?Closure $otherwise = null): Response
    {
        $otherwise ??= Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('pages', $page)) {
            return $otherwise();
        }

        return self::decide($context, $context->pageKey($page), null, $otherwise);
    }

    /**
     * @param  class-string<Widget>  $widget
     * @param  (Closure(): Response)|null  $otherwise  what Filament decides when AzGuard does not
     *
     * @throws InvalidConfigurationException when the key of the widget is not a permission segment
     */
    public static function widget(string $widget, ?Closure $otherwise = null): Response
    {
        $otherwise ??= Response::allow(...);
        $context = FilamentContext::current();

        if ($context === null || $context->excludes('widgets', $widget)) {
            return $otherwise();
        }

        return self::decide($context, $context->widgetKey($widget), null, $otherwise);
    }

    /**
     * Limits a query of a resource to the records the user may view (`{slug}.view`) when the Filament panel enforces;
     * otherwise the query is returned as it is.
     *
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     *
     * @throws VisibilityNotSupportedException when the guard panel cannot filter this query exactly
     */
    public static function visible(string $resource, Builder $query): Builder
    {
        $context = FilamentContext::current();

        if ($context === null || ! $context->enforced() || $context->excludes('resources', $resource)) {
            return $query;
        }
        $key = $context->resourceKey($resource, 'view');
        $access = $context->access();

        if (! $access->catalog()->has($key)) {
            Log::warning('AzGuard: a Filament resource is shown without its view permission.', ['panel' => $context->guardPanel(), 'permission' => $key]);

            return $query->whereKey([]);
        }

        return $access->visibility()->visibleTo($access->definition(), $query, $context->subject($access), $key);
    }

    /**
     * The `Gate::before` hook: false for an ability of Filament on a model without a policy or a Gate ability, inside a
     * Filament panel that enforces; null in every other case.
     *
     * @param  array<mixed>  $arguments
     */
    public static function before(?object $user, string $ability, array $arguments = []): ?bool
    {
        $context = FilamentContext::serving();

        if ($context === null || ! $context->enforced() || ! in_array(FilamentContext::ability($ability), $context->plugin->getAbilities(), true)) {
            return null;
        }
        $model = $arguments[0] ?? null;
        $model = match (true) {
            $model instanceof Model => $model::class,
            is_string($model) && is_subclass_of($model, Model::class) => $model,
            default => null,
        };
        $gate = app(Gate::class);

        return $model === null || $gate->has($ability) || $gate->getPolicyFor($model) !== null ? null : false;
    }

    /**
     * @param  Closure(): Response  $otherwise
     */
    private static function decide(FilamentContext $context, string $key, ?Model $record, Closure $otherwise): Response
    {
        $enforced = $context->enforced();

        try {
            $access = $context->access();
            $subject = $context->subject($access);

            if ($subject === null) {
                return $enforced ? self::refused('azguard.filament.subject') : $otherwise();
            }

            return GateBridge::toGateResult($access->decide(AccessRequest::for($subject, PermissionKey::of($context->guardPanel(), $key))->on(null, $record)));
        } catch (UnknownPermissionException) {
            if (! $enforced) {
                return $otherwise();
            }
            Log::warning('AzGuard: a Filament permission has no definition.', ['panel' => $context->guardPanel(), 'permission' => $key]);

            return self::refused('azguard.filament.definition');
        } catch (Throwable $error) {
            Log::warning('AzGuard: Filament authorization failed.', ['panel' => $context->guardPanel(), 'permission' => $key, 'exception' => $error::class]);

            return self::refused('gate_error');
        }
    }

    private static function refused(string $code): Response
    {
        return Response::deny(code: $code)->withStatus(403);
    }
}

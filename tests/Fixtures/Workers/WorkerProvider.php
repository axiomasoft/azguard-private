<?php

declare(strict_types=1);

namespace App\Providers;

require_once __DIR__.'/WorkerProbe.php';

use App\Models\User;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesPermissions;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Contracts\Tenancy\TenantMembership;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\PermissionAuthority;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use AzGuard\Tenancy\TenantPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class WorkerProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(WorkerPanelA::class);
        $this->app->register(WorkerPanelB::class);
    }

    public function boot(): void
    {
        Relation::morphMap(['user' => User::class, 'org' => WorkerTenant::class]);
    }
}

final class WorkerTenant extends Model {}

final class WorkerMembership implements TenantMembership
{
    public function isMember(SubjectRef $subject, TenantRef|AssignmentScopeRef $tenant): bool
    {
        return true;
    }
}

final class WorkerSource implements ProvidesGrants, ProvidesPermissions
{
    public function id(): string
    {
        return 'worker';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function volatility(): Volatility
    {
        return Volatility::Stable;
    }

    public function permissions(Panel $panel, ?TenantRef $tenant = null): iterable
    {
        yield new PermissionDefinition('orders.view', PermissionAuthority::Grants);
    }

    public function grants(SubjectRef $subject, array $scopes, EvaluationContext $context): iterable
    {
        if ($subject->id() === '3') {
            throw new RuntimeException('source failure');
        }
        foreach ($scopes as $scope) {
            if ($subject->id() === '1' && $scope->tenant->id() === 'A') {
                yield Grant::of(PermissionPattern::of($context->panel()->id(), 'orders.view'), 'worker', $scope);
            }
        }
    }
}

abstract class WorkerPanel extends PanelProvider
{
    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class)->permissions([new WorkerSource])
            ->tenants(TenantPolicy::required(WorkerTenant::class)->requireMembership(new WorkerMembership))
            ->before(static function (AccessRequest $request, EvaluationContext $context): BeforeResult {
                if ($request->subject()->id() === '4') {
                    throw new RuntimeException('hook failure');
                }

                return BeforeResult::Continue;
            });
    }
}

final class WorkerPanelA extends WorkerPanel
{
    public static function getId(): string
    {
        return 'worker-a';
    }
}
final class WorkerPanelB extends WorkerPanel
{
    public static function getId(): string
    {
        return 'worker-b';
    }
}

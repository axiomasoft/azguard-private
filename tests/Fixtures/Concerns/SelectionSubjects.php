<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Concerns;

use AzGuard\Authorization\Authorizer;
use AzGuard\Concerns\SubjectAccess;
use AzGuard\Panels\CurrentPanel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelRegistry;
use AzGuard\Panels\PanelResolver;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\CabinetPanel;
use AzGuard\Tests\Fixtures\Panels\InvoicePermission;
use AzGuard\Tests\Fixtures\Panels\OrderPermission;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\Seller;
use AzGuard\Tests\Fixtures\Panels\SharedPermission;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\Panels\Vendor;
use Closure;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The selection matrix of `PanelWorld::adminAndCabinet()` with subjects that use the trait: `TraitUser` is a `User`,
 * `TraitVendor` and `TraitSeller` stand for `Vendor` and `Seller`.
 */
final class SelectionSubjects
{
    /** @var array<class-string<Model>, class-string<Model>> matrix subject => subject with the trait */
    public const array SUBJECTS = [
        Vendor::class => TraitVendor::class,
        Seller::class => TraitSeller::class,
        User::class => TraitUser::class,
    ];

    /** @return array{0: PanelResolver, 1: CurrentPanel, 2: PanelRegistry} */
    public static function compile(bool $adminIsDefault): array
    {
        [$resolver, $current, $registry] = PanelWorld::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
                ->for([TraitUser::class, TraitVendor::class])->default($adminIsDefault)
                ->permissions([OrderPermission::class, SharedPermission::class]),
            CabinetPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel
                ->for([TraitUser::class, TraitSeller::class])
                ->permissions([InvoicePermission::class, SharedPermission::class]),
        ]);
        app()->instance(PanelRegistry::class, $registry);
        app()->instance(CurrentPanel::class, $current);
        app()->instance(PanelResolver::class, $resolver);
        app()->forgetInstance(Authorizer::class);

        return [$resolver, $current, $registry];
    }

    /** A saved-looking subject with the trait for a matrix subject. */
    public static function subject(string $matrixModel): Model
    {
        $class = self::SUBJECTS[$matrixModel];

        return (new $class)->forceFill(['id' => 1]);
    }

    /** The panel the trait of the model picks for a check of the permission with an optional `guard:`. */
    public static function traitPanel(Model $model, string|UnitEnum $permission, ?string $guard): string
    {
        /** @var Closure(): SubjectAccess $pick */
        $pick = Closure::bind(fn (): SubjectAccess => $this->azguardAccess($guard, [$permission]), $model, $model::class);

        return $pick()->panel()->id();
    }
}

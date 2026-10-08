<?php

declare(strict_types=1);

namespace App\Guards\Shop;

use App\Guards\Shop\Permissions\OrderPermission;
use App\Guards\Shop\Roles\ManagerRole;
use App\Guards\Shop\Roles\MemberRole;
use App\Models\User;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Panels\PanelProvider;
use Illuminate\Database\Eloquent\Relations\Relation;

final class ShopGuardPanelProvider extends PanelProvider
{
    public static function getId(): string
    {
        return 'shop';
    }

    public function panel(PanelBuilder $panel): PanelBuilder
    {
        return $panel->for(User::class, guard: 'web')
            ->permissions([OrderPermission::class])
            ->roles([MemberRole::class, ManagerRole::class]);
    }

    public function boot(): void
    {
        Relation::morphMap(['user' => User::class]);
    }
}

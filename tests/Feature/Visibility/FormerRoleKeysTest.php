<?php

declare(strict_types=1);

use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Roles\Attributes\FormerKeys;
use AzGuard\Roles\Attributes\Role;
use AzGuard\Roles\BaseRole;
use AzGuard\Tests\Fixtures\Visibility\VisibilityProject;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use Illuminate\Support\Facades\Log;

#[Role('renamed')]
#[FormerKeys('old-role')]
class VisibilityRenamedRole extends BaseRole
{
    public function permissions(): array
    {
        return ['orders.view'];
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('rejects and consistently diagnoses former and unknown role keys without redirecting', function (string $key, string $reason, ?string $current): void {
    Log::spy();
    [$visibility, $panel, $authorizer] = W::compile(new VisibilitySource(roles: [W::role(key: $key)]), fn (PanelBuilder $p) => $p->roles([VisibilityRenamedRole::class]));
    $request = AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, VisibilityProject::query()->firstOrFail());
    $explanation = $authorizer->explain($panel, $request);
    expect($explanation->decision()->allowed())->toBeFalse()
        ->and(collect($explanation->steps())->contains(fn (array $step): bool => $step['result'] === $reason))->toBeTrue()
        ->and($visibility->visibleTo($panel, VisibilityProject::query(), W::subject(), 'orders.view')->count())->toBe(0);
    Log::shouldHaveReceived('notice')->twice()->with('AzGuard ignored role contribution.', Mockery::on(fn (array $data): bool => $data['component'] === VisibilitySource::class && $data['reason'] === $reason && $data['role'] === 'admin:'.$key && $data['current_key'] === $current));
})->with([['old-role', 'former_role_key', 'renamed'], ['missing', 'unknown_role', null]]);

<?php

declare(strict_types=1);

use AzGuard\Exceptions\ConflictingPanelException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Guards\Crm\Permissions\Clients\ClientPermission;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User as CrmUser;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Panels\TestPanel;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

it('resolves a key of the admin panel to admin and never to the default panel', function (string $key): void {
    [$resolver, $current] = PanelWorld::compile([
        TestPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class)->default(),
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->for(User::class),
    ]);
    $user = new User;

    expect($resolver->resolve($user)['panel']->id())->toBe('test')
        ->and($resolver->resolve($user, $key)['panel']->id())->toBe('admin')
        ->and($resolver->resolve($user, $key)['key']?->full())->toBe('admin:users.delete')
        ->and($resolver->owner($key, $user)?->id())->toBe('admin');

    $current->set($resolver->resolve(panel: 'test')['panel']);

    expect($resolver->resolve($user, $key)['panel']->id())->toBe('admin')
        ->and($resolver->resolve($user, 'users.delete')['panel']->id())->toBe('test');
})->with(['admin:users.delete', 'admin.users.delete']);

it('P01c answers a key of the backoffice panel from backoffice in the trait, the facade and the panel access', function (): void {
    CrmWorld::seed();
    ChangeWorld::panel();

    try {
        // Anna is a seller in crm only; the same key held in crm is true, in backoffice false.
        $anna = CrmUser::query()->findOrFail(1);
        $client = Client::query()->findOrFail(1);
        $request = static fn (string $panel): AccessRequest => AccessRequest::for(SubjectRef::of('crm.user', 1), PermissionKey::of($panel, 'clients.update'))->on(null, $client);

        $held = [
            $anna->hasPermission('crm:clients.update', on: $client),
            AzGuard::check($anna, 'crm:clients.update', $client),
            AzGuard::check($anna, ClientPermission::Update, $client, 'crm'),
            AzGuard::panel('crm')->for($anna)->hasPermission('crm:clients.update', $client),
            AzGuard::panel('crm')->decide($request('crm'))->allowed(),
        ];
        $foreign = [
            $anna->hasPermission('backoffice:clients.update', on: $client),
            AzGuard::check($anna, 'backoffice:clients.update', $client),
            AzGuard::check($anna, ClientPermission::Update, $client, 'backoffice'),
            AzGuard::panel('backoffice')->for($anna)->hasPermission('backoffice:clients.update', $client),
            AzGuard::panel('backoffice')->decide($request('backoffice'))->allowed(),
        ];

        expect(array_unique($held))->toBe([true])
            ->and(array_unique($foreign))->toBe([false])
            ->and(fn () => AzGuard::panel('backoffice')->for($anna)->hasPermission('crm:clients.update', $client))->toThrow(ConflictingPanelException::class)
            ->and(fn () => AzGuard::panel('backoffice')->decide($request('crm')))->toThrow(ConflictingPanelException::class);
    } finally {
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
    }
});

it('P01c answers azguard.can for a key of the backoffice panel from backoffice inside the crm request panel', function (): void {
    HttpWorld::seed();

    try {
        HttpWorld::panel();
        Route::middleware([...HttpWorld::BINDINGS, 'azguard.panel:crm'])->group(function (): void {
            foreach (['backoffice:clients.update', 'crm:clients.update', 'clients.update'] as $key) {
                Route::get('/p01c/'.$key.'/{client}', static fn (Client $client): string => 'held')->middleware('azguard.can:'.$key.',client');
            }
        });
        HttpWorld::actingAs(1);

        // Anna is a seller in crm only: the request panel crm never answers a key of backoffice.
        $this->get('/p01c/crm:clients.update/1', ['X-Tenant' => '1'])->assertOk();
        $this->get('/p01c/clients.update/1', ['X-Tenant' => '1'])->assertOk();
        $this->get('/p01c/backoffice:clients.update/1', ['X-Tenant' => '1'])->assertForbidden();
        expect(AzGuard::check(CrmUser::query()->findOrFail(1), 'backoffice:clients.update', Client::query()->findOrFail(1)))->toBeFalse();
    } finally {
        CrmWorld::resetRuntime();
        Carbon::setTestNow();
        Relation::morphMap([], false);
    }
});

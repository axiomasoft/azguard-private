<?php

declare(strict_types=1);

use AzGuard\Changes\Change;
use AzGuard\Exceptions\UnsupportedDirectWriteException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\PermissionPattern;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Schema\WriterSchema;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Sources\StaticSource;

it('describes the writer of a panel by capabilities and keeps the source class out of the supported surface', function (Closure $make, ?WriterSchema $expected): void {
    $source = $make();
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([$source])]);
    $panel = $registry->get('admin');

    expect($panel->writer())->toEqual($expected)
        ->and($panel->isWritable())->toBe($expected !== null)
        ->and($panel->writerSource() instanceof DatabaseSource)->toBe($expected !== null);
})->with([
    'a database source' => [fn () => DatabaseSource::make(), new WriterSchema('default', false, false)],
    'dynamic permissions' => [fn () => DatabaseSource::make()->dynamicPermissions(), new WriterSchema('default', true, false)],
    'roles only' => [fn () => DatabaseSource::make()->rolesOnly(), new WriterSchema('default', false, true)],
    'a source that only reads' => [fn () => StaticSource::names('app', 'posts.view'), null],
]);

it('stores through the database source only inside the transaction of the change pipeline', function (): void {
    [, , $registry] = PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([DatabaseSource::make()])]);
    $writer = $registry->get('admin')->writerSource();
    $change = Change::grant('admin', AccessScope::in(TenantRef::global()), SubjectRef::of('user', '1'),
        PermissionPattern::of('admin', 'orders.view'), IdentityCodec::DEFAULT_ORIGIN, ActorRef::system('writer-test'));

    expect($writer)->toBeInstanceOf(DatabaseSource::class)
        ->and(fn () => $writer->apply($change))->toThrow(UnsupportedDirectWriteException::class);
});

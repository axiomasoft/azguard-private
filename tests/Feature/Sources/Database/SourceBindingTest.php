<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\EvaluationFrame;
use AzGuard\Configuration\Internal\AzGuardConfig;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Panels\Panel;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Sources\SourceManager;
use AzGuard\Storage\Schema\StorageSchema;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseRoleGrant;
use AzGuard\Tests\Fixtures\Sources\Database\DatabaseWorld;

it('binds object and named writers while preserving factory configuration and panel ownership', function (): void {
    $source = DatabaseSource::make()->rolesOnly();
    [$panel] = DatabaseWorld::compile($source);
    expect($source->isRolesOnly())->toBeTrue()->and($source->storageModels())->not->toBeEmpty()
        ->and($source->descriptor()->id)->toBe('default');
    expect(fn () => $source->bindPanel('other'))->toThrow(DefinitionException::class);

    app(SourceManager::class)->extend('bound-writer', static fn () => DatabaseSource::make()->rolesOnly());
    [, , $registry] = PanelWorld::compile([AdminPanel::class => fn ($p) => $p->permissions(['bound-writer'])]);
    app()->instance(PanelRegistry::class, $registry);
    $named = $registry->get('admin');
    $writer = $named->writerSource();
    expect($writer)->toBeInstanceOf(DatabaseSource::class)->and($writer->isRolesOnly())->toBeTrue()
        ->and($writer->storageModels())->not->toBeEmpty();
});

it('reads with writer-free and manually constructed panels and resolves services per scope', function (): void {
    app(StorageSchema::class)->create('default');

    try {
        DatabaseWorld::insert('role', [DatabaseWorld::row()]);
        [$panel, $frame] = DatabaseWorld::compile(new class implements Source
        {
            public function id(): string
            {
                return 'read-only';
            }
        });
        expect($panel->writerSource())->toBeNull();

        $config = app(AzGuardConfig::class);
        $count = 0;
        app()->scoped(AzGuardConfig::class, function () use ($config, &$count) {
            $count++;

            return $config;
        });
        $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class);
        expect(DatabaseWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame)))->toHaveCount(1);
        $source->storageModels();
        expect($count)->toBe(1);
        app()->forgetScopedInstances();
        $source->storageModels();
        expect($count)->toBe(2);
        expect(fn () => $source->bindPanel('other'))->toThrow(DefinitionException::class);

        $manual = new Panel('admin', 'Admin', false, $panel->settings(), [], []);
        $manualFrame = new EvaluationFrame($manual, $frame->scope(), $frame->state(), $frame->now(), $frame->actor());
        $manualSource = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class);
        expect(DatabaseWorld::items($manualSource->roleGrants(SubjectRef::of('user', 1), [$manualFrame->scope()], $manualFrame)))->toHaveCount(1);
    } finally {
        app(StorageSchema::class)->drop('default');
    }
});

it('uses panel services for an independently configured factory source with an evaluation context', function (): void {
    app(StorageSchema::class)->create('default');

    try {
        DatabaseWorld::insert('role', [DatabaseWorld::row()]);
        [, $frame] = DatabaseWorld::compile(DatabaseSource::make());
        $source = DatabaseSource::make()->models(roleGrant: DatabaseRoleGrant::class);
        $roles = DatabaseWorld::items($source->roleGrants(SubjectRef::of('user', 1), [$frame->scope()], $frame));

        expect($roles)->toHaveCount(1)->and($source->storageModels()['role_grant'])->toBe(DatabaseRoleGrant::class);
        expect(fn () => $source->bindPanel('other'))->toThrow(DefinitionException::class);
    } finally {
        app(StorageSchema::class)->drop('default');
    }
});

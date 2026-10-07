<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Exceptions\InvalidConfigurationException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;

it('configures the built-in folder source once and keeps a clone of folder names', function (): void {
    $source = FolderSource::make();
    $named = $source->folders(roles: 'Codes');

    expect($source->id())->toBe('folder')
        ->and($source->isDynamic())->toBeFalse()
        ->and($source)->not->toBe($named)
        ->and($source->folderNames()['roles'])->toBeNull()
        ->and($named->folderNames()['roles'])->toBe('Codes');
});

it('rejects a second folder source and folder names that sit on top of each other', function (): void {
    expect(fn () => PanelWorld::compile([
        AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
            FolderSource::make(),
            FolderSource::make()->folders(roles: 'Codes'),
        ]),
    ]))->toThrow(DefinitionException::class, 'more than one folder source')
        ->and(fn () => PanelWorld::compile([
            AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->permissions([
                FolderSource::make()->folders(permissions: 'Same', policies: 'Same'),
            ]),
        ]))->toThrow(InvalidConfigurationException::class, 'must be different');
});

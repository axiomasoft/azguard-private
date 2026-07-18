<?php

declare(strict_types=1);

use AzGuard\Panels\Panel;

test('resolvePermission добавляет префикс панели', function (): void {
    $panel = Panel::make()->id(id: 'app')->scopedByPanelId(condition: true);

    expect($panel->resolvePermission(permission: 'documents.view'))->toBe('app.documents.view');
});

test('resolvePermission без scopedByPanelId возвращает ключ как есть', function (): void {
    $panel = Panel::make()->id(id: 'app')->scopedByPanelId(condition: false);

    expect($panel->resolvePermission(permission: 'documents.view'))->toBe('documents.view');
});

<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\FencesReads;
use AzGuard\Contracts\Sources\ProvidesGrants;
use AzGuard\Contracts\Sources\ProvidesRoleGrants;
use AzGuard\Contracts\Sources\Volatility;
use AzGuard\Filament\Sources\FilamentSource;
use AzGuard\Sources\Folder\FolderSource;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Sources\Relation\RelationSource;
use AzGuard\Tests\Fixtures\Sources\Relation\ProjectDefinition;

it('built-in unsupported stable-cache sources cannot qualify for stable authority reuse', function (): void {
    $sources = [FolderSource::make(), RelationSource::make(new ProjectDefinition, 'members', 'pivot.role'), GateSource::make(), FilamentSource::make('admin')];
    foreach ($sources as $source) {
        $authority = $source instanceof ProvidesGrants || $source instanceof ProvidesRoleGrants;
        $stable = $authority && $source->volatility() === Volatility::Stable;
        expect($authority && $stable && $source instanceof FencesReads)->toBeFalse();
    }
});

<?php

declare(strict_types=1);

use AzGuard\Sources\SourceManager;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Http\HttpWorld;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
 * V84 (about part): `php artisan about` shows the AzGuard section.
 */

beforeEach(function (): void {
    HttpWorld::seed();
    HttpWorld::panel();
});
afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

/** @return array<string, string> the rows of the AzGuard section */
function aboutSection(): array
{
    expect(Artisan::call('about', ['--json' => true, '--only' => 'azguard']))->toBe(0);
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return $about['az_guard'];
}

it('V84 shows the version, the panels with their storage and state version, the host keys and the cache state', function (): void {
    $section = aboutSection();

    expect($section)->toHaveKeys(['version', 'catalog_cache', 'host_keys', 'named_sources', 'panel_crm', 'panel_backoffice'])
        ->and($section['version'])->toBeString()->not->toBe('')
        ->and($section['host_keys'])->toBe('string')
        ->and($section['named_sources'])->toBe('none')
        ->and($section['panel_crm'])->toContain('sources: folder, database')->toContain('storage: default')->toContain('state version: 5')
        ->and($section['panel_backoffice'])->toContain('state version: none');
});

it('V84 lists the named sources registered with the factory', function (): void {
    app(SourceManager::class)->extend('ldap', static fn (): never => throw new LogicException('not built'));

    expect(aboutSection()['named_sources'])->toBe('ldap');
});

it('V84 reports an unreachable storage as unavailable, without an error and without a secret', function (): void {
    Schema::drop('azg_panel_state');

    $section = aboutSection();

    expect($section['panel_crm'])->toContain('state version: unavailable')
        ->and($section['version'])->toBeString()
        ->and(implode(' ', $section))->not->toContain('azg_panel_state')->not->toContain('SQLSTATE');
});

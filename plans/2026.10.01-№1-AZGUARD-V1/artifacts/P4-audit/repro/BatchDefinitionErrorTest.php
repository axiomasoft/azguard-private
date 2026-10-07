<?php

declare(strict_types=1);

use AzGuard\Exceptions\DefinitionException;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RecordingRestriction;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(TestCase::class);

beforeEach(function (): void {
    Relation::morphMap(['user' => User::class], false);
    Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));
    Schema::create('users', fn (Blueprint $table) => $table->id());
    User::query()->insert(['id' => 1]);
});
afterEach(function (): void {
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

it('A07 decideMany turns a duplicate restriction key configuration error into SourceError decisions', function (): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource(direct: [AuthorizationWorld::grant()]), fn (PanelBuilder $panel) => $panel->restrictions([new RecordingRestriction, new RecordingRestriction]));
    $scalar = null;
    try {
        $engine->decide($panel, $request);
    } catch (DefinitionException $e) {
        $scalar = $e::class;
    }
    $batch = null;
    try {
        $set = $engine->decideMany([$request]);
        $batch = $set->get(0)->reason->value;
    } catch (DefinitionException $e) {
        $batch = $e::class;
    }
    dump(['scalar' => $scalar, 'batch' => $batch]);
    expect($batch)->toBe($scalar);
});

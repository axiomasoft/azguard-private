<?php

declare(strict_types=1);

use AzGuard\Testing\FakeSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\BooleanBeforeHook;
use AzGuard\Tests\Fixtures\Contracts\Broken\ColonScopeResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\ColonSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\CrossedSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\DriftingIdPlugin;
use AzGuard\Tests\Fixtures\Contracts\Broken\DriftingIdSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\DriftingKeyRestriction;
use AzGuard\Tests\Fixtures\Contracts\Broken\DriftingScopeResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\DriftingSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\EveryPanelSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\FlipFlopBeforeHook;
use AzGuard\Tests\Fixtures\Contracts\Broken\FlipFlopRestriction;
use AzGuard\Tests\Fixtures\Contracts\Broken\FlipFlopSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\GhostSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\GlobalScopeResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\InvalidIdSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\LeakyPipe;
use AzGuard\Tests\Fixtures\Contracts\Broken\OnePanelPlugin;
use AzGuard\Tests\Fixtures\Contracts\Broken\OwnClockSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\PermissiveSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\RegisteringBootPlugin;
use AzGuard\Tests\Fixtures\Contracts\Broken\StrictScopeResolver;
use AzGuard\Tests\Fixtures\Contracts\Broken\SwallowingPipe;
use AzGuard\Tests\Fixtures\Contracts\Broken\ThrowingBeforeHook;
use AzGuard\Tests\Fixtures\Contracts\Broken\ThrowingRestriction;
use AzGuard\Tests\Fixtures\Contracts\Broken\UnderstatedSource;
use AzGuard\Tests\Fixtures\Contracts\Broken\UnguardedWriter;
use AzGuard\Tests\Fixtures\Contracts\Broken\WritingBeforeHook;
use AzGuard\Tests\Fixtures\Contracts\Broken\WritingPipe;
use AzGuard\Tests\Fixtures\Contracts\Broken\WritingRestriction;
use AzGuard\Tests\Fixtures\Contracts\DenyEditHook;
use AzGuard\Tests\Fixtures\Contracts\GoodScopeResolver;
use AzGuard\Tests\Fixtures\Contracts\GoodSubjectResolver;
use AzGuard\Tests\Fixtures\Contracts\LabelPlugin;
use AzGuard\Tests\Fixtures\Contracts\PassThroughPipe;
use AzGuard\Tests\Fixtures\Contracts\Probes\HookProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\PluginProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\RestrictionProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\ScopeResolverProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\SourceProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\SubjectResolverProbe;
use AzGuard\Tests\Fixtures\Contracts\ReadOnlyEditRestriction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;

/*
 * A suite that cannot be failed proves nothing. Each case runs one test of a suite on an implementation that
 * keeps the guarantee, which passes, and on one that breaks it, which fails — the same test, so the failure is the
 * suite's and not an accident of the stand.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    Schema::create('contract_probe', static function (Blueprint $table): void {
        $table->id();
        $table->string('note');
    });
    app('db')->connection('secondary')->getSchemaBuilder()->create('contract_leak', static function (Blueprint $table): void {
        $table->id();
        $table->string('note');
    });
    DriftingIdPlugin::$calls = 0;
    OnePanelPlugin::$panel = null;
    FlipFlopSource::$reads = 0;
    FlipFlopBeforeHook::$calls = 0;
});

it('fails the source suite on a source that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken, string $failure): void {
    (new SourceProbe(static fn () => new FakeSource))->run($method);

    expect(fn () => (new SourceProbe($broken))->run($method))->toThrow($failure);
})->with([
    'invalid id' => ['sourceHasAValidStableId', static fn () => new InvalidIdSource, AssertionFailedError::class],
    'drifting id' => ['sourceHasAValidStableId', static fn () => new DriftingIdSource, AssertionFailedError::class],
    'answers for every panel' => ['sourceAnswersOnlyForItsOwnPanel', static fn () => new EveryPanelSource, AssertionFailedError::class],
    'other answer on the same version' => ['sameVersionGivesTheSameAnswer', static fn () => new FlipFlopSource, AssertionFailedError::class],
    'reads its own clock' => ['sourceTakesNowFromTheContext', static fn () => new OwnClockSource, AssertionFailedError::class],
    'describes fewer capabilities than it has' => ['describeMatchesWhatTheSourceGives', static fn () => new UnderstatedSource, AssertionFailedError::class],
    'stores outside the transaction' => ['writerStoresOnlyInsideThePipelineTransaction', static fn () => new UnguardedWriter, AssertionFailedError::class],
]);

it('fails the restriction suite on a restriction that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken, string $failure): void {
    (new RestrictionProbe(static fn () => new ReadOnlyEditRestriction))->run($method);

    expect(fn () => (new RestrictionProbe($broken))->run($method))->toThrow($failure);
})->with([
    'unstable key' => ['restrictionHasAStableKey', static fn () => new DriftingKeyRestriction, AssertionFailedError::class],
    'other answer to the same request' => ['restrictionAnswersTheSameRequestTheSameWay', static fn () => new FlipFlopRestriction, AssertionFailedError::class],
    'throws on an ordinary request' => ['restrictionDoesNotFailOnOrdinaryRequestsAndNamesItsDenial', static fn () => new ThrowingRestriction, AssertionFailedError::class],
    'writes while it decides' => ['restrictionWritesNothingWhileItDecides', static fn () => new WritingRestriction, AssertionFailedError::class],
]);

it('fails the hook suite on a hook or a pipe that breaks a guarantee and passes it on one that keeps it', function (string $method, ?Closure $hook, ?Closure $pipe, string $failure): void {
    $good = [static fn () => DenyEditHook::class, static fn () => new PassThroughPipe];
    (new HookProbe(...$good))->run($method);

    expect(fn () => (new HookProbe($hook ?? $good[0], $pipe ?? $good[1]))->run($method))->toThrow($failure);
})->with([
    'before hook writes' => ['beforeHookAnswersWithABeforeResultAndWritesNothing', static fn () => WritingBeforeHook::class, null, AssertionFailedError::class],
    'before hook answers with a boolean' => ['beforeHookAnswersWithABeforeResultAndWritesNothing', static fn () => BooleanBeforeHook::class, null, AssertionFailedError::class],
    'before hook throws' => ['beforeHookAnswersWithABeforeResultAndWritesNothing', static fn () => ThrowingBeforeHook::class, null, AssertionFailedError::class],
    'before hook answers differently' => ['beforeHookAnswersWithABeforeResultAndWritesNothing', static fn () => FlipFlopBeforeHook::class, null, AssertionFailedError::class],
    'pipe writes around the writer' => ['pipeWritesNothingBesidesTheWriter', null, static fn () => new WritingPipe, AssertionFailedError::class],
    'cancelled change leaves a row' => ['cancelledChangeLeavesNoTrace', null, static fn () => new LeakyPipe, AssertionFailedError::class],
    'pipe drops the change' => ['cancelledChangeLeavesNoTrace', null, static fn () => new SwallowingPipe, AssertionFailedError::class],
]);

it('fails the plugin suite on a plugin that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken, string $failure): void {
    (new PluginProbe(static fn () => new LabelPlugin))->run($method);

    expect(fn () => (new PluginProbe($broken))->run($method))->toThrow($failure);
})->with([
    'unstable id' => ['pluginHasAStableId', static fn () => new DriftingIdPlugin, AssertionFailedError::class],
    'works on one panel only' => ['pluginBuildsOnTwoPanelsAndTouchesOnlyTheOneItIsAttachedTo', static fn () => new OnePanelPlugin, Exception::class],
    'registers a panel from boot' => ['bootDoesNotChangeThePanel', static fn () => new RegisteringBootPlugin, Exception::class],
]);

it('fails the subject resolver suite on a resolver that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken, string $failure): void {
    (new SubjectResolverProbe(static fn () => new GoodSubjectResolver))->run($method);

    expect(fn () => (new SubjectResolverProbe($broken))->run($method))->toThrow($failure);
})->with([
    'other reference for the same subject' => ['resolvingIsIdempotent', static fn () => new DriftingSubjectResolver, AssertionFailedError::class],
    'colon in the type' => ['referenceSurvivesTheCodecAndHasNoColonInItsType', static fn () => new ColonSubjectResolver, Exception::class],
    'model of another subject' => ['modelOfTheReferenceResolvesBackToTheSameReference', static fn () => new CrossedSubjectResolver, AssertionFailedError::class],
    'model of a missing subject' => ['missingSubjectHasNoModel', static fn () => new GhostSubjectResolver, AssertionFailedError::class],
    'resolves what is not a subject' => ['whatIsNotASubjectIsRefused', static fn () => new PermissiveSubjectResolver, AssertionFailedError::class],
]);

it('fails the scope resolver suite on a resolver that breaks a guarantee and passes it on one that keeps it', function (string $method, Closure $broken, string $failure): void {
    (new ScopeResolverProbe(static fn () => new GoodScopeResolver))->run($method);

    expect(fn () => (new ScopeResolverProbe($broken))->run($method))->toThrow($failure);
})->with([
    'other scope for the same request' => ['resolvingIsIdempotent', static fn () => new DriftingScopeResolver, AssertionFailedError::class],
    'colon in the type' => ['scopeSurvivesTheCodecAndHasNoColonInItsType', static fn () => new ColonScopeResolver, Exception::class],
    'global scope for an unscoped request' => ['requestWithoutAScopeGivesNull', static fn () => new GlobalScopeResolver, AssertionFailedError::class],
    'fails on an unscoped request' => ['requestWithoutAScopeGivesNull', static fn () => new StrictScopeResolver, Exception::class],
]);

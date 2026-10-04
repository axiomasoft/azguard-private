<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\DescribesSchema;
use AzGuard\Contracts\Sources\ProvidesPolicies;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Policies\PolicyBinding;
use AzGuard\Sources\Gate\GateSource;
use AzGuard\Tests\Fixtures\Panels\User;
use AzGuard\Tests\Fixtures\PoliciesGate\GateGrantSource;
use AzGuard\Tests\Fixtures\PoliciesGate\GatePermission;
use AzGuard\Tests\Fixtures\PoliciesGate\GateRecord;
use AzGuard\Tests\Fixtures\PoliciesGate\GateWorld;
use AzGuard\Tests\Fixtures\PoliciesGate\NativePolicy;
use AzGuard\Tests\Fixtures\PoliciesGate\PhpPolicy;
use Illuminate\Auth\Access\Gate as NativeGate;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    GateWorld::seed();
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('describes immutable typed gate mappings without inventing a PHP policy', function (): void {
    Gate::define('beta-access', static fn (User $user): bool => true);
    $empty = GateSource::make();
    $source = $empty->map(GatePermission::Access, 'beta-access');
    [,, $registry] = GateWorld::compile([$source]);
    $panel = $registry->get('admin');
    $rows = iterator_to_array($source->policies($panel));

    expect($source)->toBeInstanceOf(ProvidesPolicies::class)->toBeInstanceOf(DescribesSchema::class)
        ->and($source->id())->toBe('gate')
        ->and(iterator_to_array($empty->policies($panel)))->toBe([])
        ->and($rows)->toHaveCount(1)->and($rows[0])->toBeInstanceOf(PolicyBinding::class)
        ->and($rows[0]->kind)->toBe('gate')->and($rows[0]->permission)->toBe(GatePermission::Access)
        ->and($rows[0]->policy)->toBeNull()->and($rows[0]->method)->toBeNull()
        ->and($rows[0]->ability)->toBe('beta-access')->and($rows[0]->resourceModel)->toBeNull()
        ->and($source->describe($panel)->dynamic)->toBeFalse()
        ->and($source->describe($panel)->capabilities)->toContain(ProvidesPolicies::class);
});

it('calls the original closure with the target subject and ignores global Gate before and after hooks', function (): void {
    $seen = null;
    $hooks = 0;
    Gate::define('beta-access', static function (User $user) use (&$seen): bool {
        $seen = $user;

        return false;
    });
    Gate::before(static function () use (&$hooks): bool {
        $hooks++;

        return true;
    });
    Gate::after(static function () use (&$hooks): bool {
        $hooks++;

        return true;
    });
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);
    $decision = $authorizer->decide($panel, GateWorld::request());

    expect($decision->reason)->toBe(DecisionReason::Policy)->and($decision->allowed())->toBeFalse()
        ->and($seen)->toBeInstanceOf(User::class)->and($seen->getKey())->toBe(1)->and($hooks)->toBe(0);
});

it('interprets a true native ability according to RequiresGrant instead of treating it as authority', function (): void {
    Gate::define('beta-access', static fn (User $user): bool => true);
    [$authorizer, $panel] = GateWorld::compile([
        GateSource::make()->map(GatePermission::Access, 'beta-access')->map(GatePermission::Veto, 'beta-access'),
    ]);

    expect($authorizer->decide($panel, GateWorld::request())->allowed())->toBeTrue()
        ->and($authorizer->decide($panel, GateWorld::request(GatePermission::Veto))->reason)->toBe(DecisionReason::NotGranted);
});

it('lets a native ability veto a qualified grant and preserves native response details', function (): void {
    $result = true;
    Gate::define('beta-access', static function (User $user) use (&$result): bool|Response {
        return $result;
    });
    [$authorizer, $panel] = GateWorld::compile([
        new GateGrantSource,
        GateSource::make()->map(GatePermission::Access, 'beta-access')->map(GatePermission::Veto, 'beta-access'),
    ]);
    expect($authorizer->decide($panel, GateWorld::request(GatePermission::Veto))->allowed())->toBeTrue();
    $result = Response::deny('Native closed', 'beta.closed')->withStatus(403);
    $decision = $authorizer->decide($panel, GateWorld::request(GatePermission::Veto));

    expect($decision->reason)->toBe(DecisionReason::Policy)->and($decision->allowed())->toBeFalse()
        ->and($decision->message)->toBe('Native closed')->and($decision->code)->toBe('beta.closed')->and($decision->status)->toBe(403);
});

it('fails closed for a native ability exception or unsupported result', function (bool $throws): void {
    Gate::define('beta-access', static function (User $user) use ($throws): string {
        if ($throws) {
            throw new RuntimeException('Broken native ability');
        }

        return 'truthy';
    });
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);

    expect($authorizer->decide($panel, GateWorld::request())->reason)->toBe(DecisionReason::PolicyError);
})->with([true, false]);

it('resolves a native model policy that Gate has does not report and retains its before method', function (): void {
    Gate::policy(GateRecord::class, NativePolicy::class);
    expect(Gate::has('native'))->toBeFalse();
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'native', GateRecord::class)]);
    $record = new GateRecord;
    $record->setAttribute('id', 31);
    $decision = $authorizer->decide($panel, GateWorld::request()->on(null, $record));

    expect($decision->allowed())->toBeTrue()->and(NativePolicy::$calls)->toBe(1)
        ->and(NativePolicy::$seen[1])->toBe($record);

    NativePolicy::$before = Response::deny('Closed', 'beta.closed')->withStatus(404);
    $denied = $authorizer->decide($panel, GateWorld::request()->on(null, $record));
    expect($denied->allowed())->toBeFalse()->and($denied->reason)->toBe(DecisionReason::Policy)
        ->and($denied->message)->toBe('Closed')->and($denied->status)->toBe(404)->and($denied->code)->toBe('beta.closed')
        ->and(NativePolicy::$calls)->toBe(1)->and(NativePolicy::$seen[1])->toBe('native');
});

it('unwraps native class at method abilities without invoking global hooks', function (): void {
    Gate::define('beta-access', NativePolicy::class.'@native');
    $hooks = 0;
    Gate::before(static function () use (&$hooks): bool {
        $hooks++;

        return true;
    });
    NativePolicy::$result = false;
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);

    expect($authorizer->decide($panel, GateWorld::request()->on(null, new GateRecord))->allowed())->toBeFalse()
        ->and(NativePolicy::$calls)->toBe(1)->and($hooks)->toBe(0);
});

it('does not invoke a native model method when its required resource instance is missing', function (): void {
    Gate::policy(GateRecord::class, NativePolicy::class);
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'native', GateRecord::class)]);

    expect($authorizer->decide($panel, GateWorld::request())->reason)->toBe(DecisionReason::PolicyError)
        ->and(NativePolicy::$calls)->toBe(0);
});

it('fails closed if a compiled native ability disappears', function (): void {
    Gate::define('beta-access', static fn (User $user): bool => true);
    [$authorizer, $panel] = GateWorld::compile([GateSource::make()->map(GatePermission::Access, 'beta-access')]);
    Gate::swap(new NativeGate(app(), static fn (): null => null));

    expect($authorizer->decide($panel, GateWorld::request())->reason)->toBe(DecisionReason::PolicyError);
});

it('rejects a native binding for a permission missing from the panel catalog', function (): void {
    Gate::define('beta-access', static fn (User $user): bool => true);

    expect(fn () => GateWorld::compile([GateSource::make()->map('unknown.access', 'beta-access')], [
        PolicyBinding::for(GatePermission::Access, PhpPolicy::class),
    ]))->toThrow(DefinitionException::class);
});

it('rejects missing, ambiguous and AzGuard owned ability mappings while compiling', function (string $case): void {
    $ability = match ($case) {
        'missing' => 'missing-ability',
        'local' => 'beta.access',
        'full' => 'admin:beta.access',
        'ambiguous' => 'native',
        'missing policy method' => 'missing',
    };

    if ($case !== 'missing') {
        Gate::define($ability, static fn (User $user): bool => true);
    }

    if (in_array($case, ['ambiguous', 'missing policy method'], true)) {
        Gate::policy(GateRecord::class, NativePolicy::class);
    }

    if ($case === 'missing policy method') {
        Gate::swap((new NativeGate(app(), static fn (): null => null))->policy(GateRecord::class, NativePolicy::class));
    }
    $model = in_array($case, ['ambiguous', 'missing policy method'], true) ? GateRecord::class : null;

    expect(fn () => GateWorld::compile([GateSource::make()->map(GatePermission::Access, $ability, $model)]))->toThrow(DefinitionException::class);
})->with(['missing', 'local', 'full', 'ambiguous', 'missing policy method']);

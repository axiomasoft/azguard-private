<?php

declare(strict_types=1);

use AzGuard\Authorization\ModelSubjectResolver;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Exceptions\UnknownPermissionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\AuthorizationWorld;
use AzGuard\Tests\Fixtures\Authorization\GeneratedSource;
use AzGuard\Tests\Fixtures\Authorization\RuntimePolicy;
use AzGuard\Tests\Fixtures\Panels\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('resolves morph aliases to existing models and missing rows to null', function (): void {
    Relation::morphMap(['person' => User::class]);

    try {
        [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
        $resolver = new ModelSubjectResolver;
        expect($resolver->resolve($panel, SubjectRef::of('person', 1))?->getKey())->toBe(1)->and($resolver->resolve($panel, SubjectRef::of('person', 99)))->toBeNull();
    } finally {
        Relation::morphMap([], false);
    }
});
it('passes the actual target and actor models into the evaluation frame', function (): void {
    User::query()->insert(['id' => 2, 'department' => 'support']);
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
    RuntimePolicy::$callback = function ($user, $resource, $context): bool {
        expect($user->getKey())->toBe(1)->and($context->actorModel()->getKey())->toBe(2);

        return true;
    };
    expect($engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy')), ActorRef::of((new User)->getMorphClass(), 2))->allowed())->toBeTrue();
});
it('throws direct API identity errors instead of inventing definitions or subjects', function (string $kind): void {
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource);
    $request = AccessRequest::for($kind === 'subject' ? SubjectRef::of('outsider', 1) : $request->subject(), $kind === 'permission' ? PermissionKey::of('admin', 'missing.action') : $request->permission());
    expect(fn () => $engine->decide($panel, $request))->toThrow($kind === 'subject' ? SubjectNotAcceptedException::class : UnknownPermissionException::class);
})->with(['subject', 'permission']);

it('fails closed on a subject query failure and observes the denial', function (): void {
    $seen = null;
    [$engine,$panel,$request] = AuthorizationWorld::compile(new GeneratedSource, function (PanelBuilder $panel) use (&$seen): void {
        $panel->after(function (Decision $decision) use (&$seen): void {
            $seen = $decision;
        });
    });
    Schema::drop('users');
    $decision = $engine->decide($panel, $request);
    expect($decision->reason)->toBe(DecisionReason::SourceError)->and($seen)->toBe($decision);
});

it('uses the subject model the caller holds and reads a bare reference again', function (): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource);
    $held = User::query()->findOrFail(1);
    $seen = [];
    RuntimePolicy::$callback = function ($user) use (&$seen): bool {
        $seen[] = $user;

        return true;
    };
    $policy = AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'));
    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        $reads += preg_match('/["`]users["`]/', $query->sql);
    });

    foreach (range(1, 5) as $ignored) {
        $engine->decide($panel, $policy->withSubjectModel($held));
    }
    expect($reads)->toBe(0)->and($seen)->each->toBe($held);

    $engine->decide($panel, $policy);
    expect($reads)->toBe(1)->and(end($seen))->not->toBe($held);
});

it('reads the subject again when the held model is not exactly the referenced stored row', function (Closure $model): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource);
    $given = $model();
    $seen = null;
    RuntimePolicy::$callback = function ($user) use (&$seen): bool {
        $seen = $user;

        return true;
    };
    $engine->decide($panel, AccessRequest::for($request->subject(), PermissionKey::of('admin', 'orders.policy'))->withSubjectModel($given));
    expect($seen)->toBeInstanceOf(User::class)->not->toBe($given)->and($seen->getKey())->toBe(1);
})->with([
    'unsaved' => fn () => fn () => (new User)->forceFill(['id' => 1]),
    'another key' => fn () => fn () => tap(User::query()->findOrFail(1), fn (User $user) => $user->setAttribute('id', 2)),
    'a subclass with the same alias' => fn () => fn () => (new class extends User
    {
        public function getMorphClass(): string
        {
            return (new User)->getMorphClass();
        }
    })->newQuery()->findOrFail(1),
    'not a model' => fn () => fn () => new stdClass,
]);

it('resolves only the row whose key is the id as written and never queries an id an integer key cannot hold', function (): void {
    [, $panel] = AuthorizationWorld::compile(new GeneratedSource);
    $resolver = new ModelSubjectResolver;
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    // SQLite and MySQL coerce '01' and ' 1' to the row 1, PostgreSQL rejects 'a/b' with an error: none of them is user 1.
    foreach (['a/b', 'A:B', '1.0', '9223372036854775808'] as $id) {
        expect($resolver->resolve($panel, SubjectRef::of('user', $id)))->toBeNull();
    }
    expect($queries)->toBe([]);
    expect($resolver->resolve($panel, SubjectRef::of('user', '01')))->toBeNull()
        ->and($resolver->resolve($panel, SubjectRef::of('user', '1'))?->getKey())->toBe(1);
});

it('keeps a batch whole when one subject id cannot be a key and reads no model for it', function (): void {
    [$engine, $panel, $request] = AuthorizationWorld::compile(new GeneratedSource);
    $seen = [];
    RuntimePolicy::$callback = function ($user) use (&$seen): bool {
        $seen[] = $user?->getKey();

        return true;
    };
    $policy = PermissionKey::of('admin', 'orders.policy');
    $set = $engine->decideMany([AccessRequest::for(SubjectRef::of('user', 'a/b'), $policy), AccessRequest::for(SubjectRef::of('user', '01'), $policy),
        AccessRequest::for($request->subject(), $policy)]);

    foreach ([0, 1, 2] as $index) {
        expect($set->get($index)->reason)->not->toBe(DecisionReason::SourceError);
    }
    expect($seen)->toContain(1)->not->toContain('01');
});

<?php

declare(strict_types=1);

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Tests\Fixtures\Authorization\ContinueHook;
use AzGuard\Tests\Fixtures\Authorization\InvokableOnlyHook;
use AzGuard\Tests\Fixtures\Changes\ChangeWorld as W;
use AzGuard\Tests\Fixtures\Changes\HandleOnlyPipe;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Panels\AdminPanel;
use AzGuard\Tests\Fixtures\Panels\PanelWorld;

/** The slots `before`, `after` and `changing` take the named contract or a closure of its signature, checked at compilation. */
function compileSlot(string $slot, mixed $entry): void
{
    PanelWorld::compile([AdminPanel::class => static fn (PanelBuilder $panel): PanelBuilder => $panel->{$slot}([$entry])]);
}

it('compiles a closure whose types accept what the contract passes, whatever its parameters are called', function (string $slot, Closure $closure): void {
    compileSlot($slot, $closure);

    expect(true)->toBeTrue();
})->with([
    'before, other names' => ['before', static fn (AccessRequest $a, EvaluationContext $b): BeforeResult => BeforeResult::Continue],
    'before, wider parameter types' => ['before', static fn (object $a, mixed $b): BeforeResult => BeforeResult::Continue],
    'before, union parameter type' => ['before', static fn (AccessRequest|string $a, ?EvaluationContext $b): BeforeResult => BeforeResult::Continue],
    'after, other names' => ['after', static function (AccessRequest $a, EvaluationContext $b, Decision $c): void {}],
    'after, wider parameter types' => ['after', static function (object $a, object $b, mixed $c): void {}],
    'changing, other names' => ['changing', static fn (Change $a, Closure $b): ChangeResult => $b($a)],
    'changing, callable next' => ['changing', static fn (Change $a, callable $b): ChangeResult => $b($a)],
]);

it('rejects a closure that does not match the contract when the panel compiles, naming slot and mismatch', function (string $slot, mixed $closure, string $mismatch): void {
    expect(fn () => compileSlot($slot, $closure))->toThrow(DefinitionException::class, 'slot `'.$slot.'` from {"kind":"provider"')
        ->and(fn () => compileSlot($slot, $closure))->toThrow(DefinitionException::class, $mismatch);
})->with([
    'before, too few parameters' => ['before', static fn (AccessRequest $r): BeforeResult => BeforeResult::Continue, 'it takes 1 parameters, the contract has 2'],
    'before, no parameters' => ['before', static fn (): BeforeResult => BeforeResult::Continue, 'it takes 0 parameters, the contract has 2'],
    'before, too many parameters' => ['before', static fn (AccessRequest $r, EvaluationContext $c, Decision $d): BeforeResult => BeforeResult::Continue, 'it takes 3 parameters'],
    'before, untyped parameter' => ['before', static fn ($r, EvaluationContext $c): BeforeResult => BeforeResult::Continue, 'parameter 1 has no declared type'],
    'before, wrong parameter type' => ['before', static fn (AccessRequest $r, stdClass $c): BeforeResult => BeforeResult::Continue, 'parameter 2 is declared stdClass'],
    'before, a narrower parameter type' => ['before', static fn (AccessRequest $r, EvaluationFrame $c): BeforeResult => BeforeResult::Continue, 'parameter 2 is declared AzGuard\Authorization\EvaluationFrame'],
    'before, no return type' => ['before', static fn (AccessRequest $r, EvaluationContext $c) => BeforeResult::Continue, 'the return type is not declared'],
    'before, boolean return type' => ['before', static fn (AccessRequest $r, EvaluationContext $c): bool => true, 'the return type is bool'],
    'before, nullable return type' => ['before', static fn (AccessRequest $r, EvaluationContext $c): ?BeforeResult => null, 'the return type is ?AzGuard\Kernel\Decision\BeforeResult'],
    'after, too few parameters' => ['after', static function (AccessRequest $r, Decision $d): void {}, 'it takes 2 parameters, the contract has 3'],
    'after, wrong parameter type' => ['after', static function (AccessRequest $r, EvaluationContext $c, string $d): void {}, 'parameter 3 is declared string'],
    'after, no return type' => ['after', static function (AccessRequest $r, EvaluationContext $c, Decision $d) {}, 'the return type is not declared'],
    'after, a returned value' => ['after', static fn (AccessRequest $r, EvaluationContext $c, Decision $d): bool => true, 'the return type is bool'],
    'changing, one parameter' => ['changing', static fn (Change $c): ChangeResult => throw new RuntimeException, 'it takes 1 parameters, the contract has 2'],
    'changing, untyped next' => ['changing', static fn (Change $c, $next): ChangeResult => $next($c), 'parameter 2 has no declared type'],
    'changing, wrong next type' => ['changing', static fn (Change $c, int $next): ChangeResult => throw new RuntimeException, 'parameter 2 is declared int'],
    'changing, no return type' => ['changing', static fn (Change $c, Closure $next) => $next($c), 'the return type is not declared'],
    'changing, nullable result' => ['changing', static fn (Change $c, Closure $next): ?ChangeResult => $next($c), 'the return type is ?AzGuard\Changes\ChangeResult'],
    'changing, array result' => ['changing', static fn (Change $c, Closure $next): array => [], 'the return type is array'],
]);

it('rejects an entry that is neither the contract nor a closure', function (string $slot, mixed $entry, string $contract): void {
    expect(fn () => compileSlot($slot, $entry))->toThrow(DefinitionException::class, 'expects '.$contract);
})->with([
    'before, an invokable object' => ['before', new InvokableOnlyHook, 'AzGuard\Contracts\Authorization\BeforeHook'],
    'before, an invokable class' => ['before', InvokableOnlyHook::class, 'AzGuard\Contracts\Authorization\BeforeHook'],
    'before, an unknown class' => ['before', 'App\Hooks\Missing', 'AzGuard\Contracts\Authorization\BeforeHook'],
    'after, a before hook' => ['after', ContinueHook::class, 'AzGuard\Contracts\Authorization\AfterHook'],
    'changing, an object with only handle()' => ['changing', new HandleOnlyPipe, 'AzGuard\Contracts\Changes\ChangePipe'],
    'changing, a class with only handle()' => ['changing', HandleOnlyPipe::class, 'AzGuard\Contracts\Changes\ChangePipe'],
]);

it('calls a pipe closure positionally, not by parameter name', function (): void {
    CrmWorld::seed();
    $seen = null;
    $panel = W::panel([static function (Change $first, Closure $second) use (&$seen): ChangeResult {
        $seen = $first;

        return $second($first);
    }]);

    W::grant($panel, 'analyst', 2, 1);

    expect($seen)->toBeInstanceOf(Change::class);
})->after(fn () => CrmWorld::resetRuntime());

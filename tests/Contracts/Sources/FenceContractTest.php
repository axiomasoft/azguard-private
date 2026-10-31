<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Testing\Internal\Contracts\ContractPermission;
use AzGuard\Testing\Internal\Contracts\ContractWorld;
use AzGuard\Tests\Fixtures\Contracts\Probes\RunsAsAProbe;
use AzGuard\Tests\Fixtures\Contracts\Probes\SourceProbe;
use AzGuard\Tests\Fixtures\Sources\BrokenFenceSource;
use PHPUnit\Framework\AssertionFailedError;

final class BrokenFenceProbe
{
    use RunsAsAProbe;
    use SourceContractTests;

    public function __construct(private readonly bool $driftOnNoopRevoke = false) {}

    protected function azguardSource(): Source
    {
        return new BrokenFenceSource(driftOnNoopRevoke: $this->driftOnNoopRevoke);
    }

    protected function azguardFenceMutation(Source $source, Panel $panel): ?callable
    {
        return $source->mutate(...);
    }
}

it('rejects changed authority after a no-op revoke with the same state', function (): void {
    expect(fn () => (new BrokenFenceProbe(driftOnNoopRevoke: true))->run('fencedAuthorityChangesItsStateOnlyOnMutation'))
        ->toThrow(AssertionFailedError::class, 'The same state returned different raw authority after a no-op revoke.');
});

it('rejects a stable source whose authority changes without changing its fence', function (): void {
    expect(fn () => (new BrokenFenceProbe)->run('fencedAuthorityChangesItsStateOnlyOnMutation'))
        ->toThrow(AssertionFailedError::class, 'A grant changed authority without changing state.');
});

it('runs a supplied mutation fixture through the ordinary source contract', function (): void {
    expect(fn () => (new BrokenFenceProbe)->run('describeMatchesWhatTheSourceGives'))
        ->toThrow(AssertionFailedError::class, 'A grant changed authority without changing state.');
});

it('rejects different raw authority at the same state even when decision caching masks the drift', function (): void {
    expect(fn () => (new SourceProbe(static fn () => new BrokenFenceSource(drift: true)))->run('sameVersionGivesTheSameAnswer'))
        ->toThrow(AssertionFailedError::class, 'The same state returned different raw authority.');
});

it('bounds retries and denies when the source epoch or incarnation changes on every read', function (string $field): void {
    $panel = ContractWorld::panel(fn (PanelBuilder $builder) => $builder->permissions([new BrokenFenceSource(changing: $field)]));
    $sources = array_values(array_filter($panel->attachedSources(), fn ($source) => $source instanceof BrokenFenceSource));
    $source = $sources[0];
    $source->mutate('grant');

    $decision = ContractWorld::decide($panel, ContractWorld::subject(), ContractPermission::View);

    expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(DecisionReason::ConsistencyError)
        ->and($source->grantCalls)->toBe(3)->and($source->stateCalls)->toBe(6);
})->with(['epoch', 'incarnation']);

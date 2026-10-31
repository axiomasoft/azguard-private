<?php

declare(strict_types=1);

use AzGuard\Contracts\Sources\AssignmentScopeSelection;
use AzGuard\Contracts\Sources\Source;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Testing\Contracts\SourceContractTests;
use AzGuard\Testing\FakeSource;
use AzGuard\Tests\Fixtures\Contracts\Probes\RunsAsAProbe;
use AzGuard\Tests\Fixtures\Visibility\VisibilityClient;
use AzGuard\Tests\Fixtures\Visibility\VisibilitySource;
use AzGuard\Tests\Fixtures\Visibility\VisibilityWorld as W;
use PHPUnit\Framework\AssertionFailedError;

require_once __DIR__.'/../../Fixtures/Visibility/VisibilityWorld.php';

final class QueryParityProbe
{
    use RunsAsAProbe;
    use SourceContractTests;

    public function __construct(private readonly string $mode) {}

    protected function azguardSource(): Source
    {
        return new FakeSource;
    }

    protected function azguardQueryParity(): ?array
    {
        $source = new VisibilitySource(direct: [W::grant(1)]);

        if ($this->mode === 'broken') {
            $source->selection = static fn () => AssignmentScopeSelection::in([], []);
        } elseif ($this->mode === 'unsupported' || $this->mode === 'false-unsupported') {
            $source->selection = static fn () => null;
        }
        [$visibility, $panel, $authorizer] = W::compile($source);
        $resources = VisibilityClient::query()->orderBy('id')->get();
        $requests = $resources->map(static fn ($resource) => AccessRequest::for(W::subject(), PermissionKey::of('admin', 'orders.view'))->on(null, $resource))->all();

        return [
            'supported' => ! in_array($this->mode, ['unsupported', 'false-supported'], true),
            'scalar' => static function () use ($authorizer, $panel, $requests, $resources): array {
                $out = [];
                foreach ($requests as $i => $request) {
                    $out[$resources[$i]->id] = $authorizer->decide($panel, $request)->allowed();
                }

                return $out;
            },
            'batch' => static function () use ($authorizer, $requests, $resources): array {
                $set = $authorizer->decideMany($requests);
                $out = [];
                foreach ($requests as $i => $_) {
                    $out[$resources[$i]->id] = $set->get($i)->allowed();
                }

                return $out;
            },
            'visibility' => static function () use ($visibility, $panel, $resources): array {
                $ids = $visibility->visibleTo($panel, VisibilityClient::query(), W::subject(), 'orders.view')->pluck('id')->all();
                $out = [];
                foreach ($resources as $resource) {
                    $out[$resource->id] = in_array($resource->id, $ids, true);
                }

                return $out;
            },
        ];
    }
}

beforeEach(fn () => W::seed());
afterEach(fn () => W::reset());

it('accepts matching scalar batch and visibility results', function (): void {
    (new QueryParityProbe('supported'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('rejects an incomplete source query filter', function (): void {
    expect(fn () => (new QueryParityProbe('broken'))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class, 'Visibility differs from scalar decisions.');
});

it('runs a supplied parity fixture through the ordinary source contract', function (): void {
    expect(fn () => (new QueryParityProbe('broken'))->run('describeMatchesWhatTheSourceGives'))
        ->toThrow(AssertionFailedError::class, 'Visibility differs from scalar decisions.');
});

it('accepts a typed unsupported visibility result', function (): void {
    (new QueryParityProbe('unsupported'))->run('sourceVisibilityMatchesScalarAndBatch');
});

it('rejects a mismatch between visibility support and the runtime result', function (string $mode): void {
    expect(fn () => (new QueryParityProbe($mode))->run('sourceVisibilityMatchesScalarAndBatch'))
        ->toThrow(AssertionFailedError::class);
})->with(['false-supported', 'false-unsupported']);

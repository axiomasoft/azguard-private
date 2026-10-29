<?php

declare(strict_types=1);

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Exceptions\VisibilityNotSupportedException;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\PanelRegistry;
use AzGuard\Storage\Internal\StorageMutation;
use AzGuard\Tests\Fixtures\Crm\CrmWorld;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use AzGuard\Tests\Fixtures\Crm\Models\User;
use AzGuard\Tests\Fixtures\Parity\ParityCase;
use AzGuard\Tests\Fixtures\Parity\ParityRunner;
use AzGuard\Tests\Fixtures\Parity\ParityWorld;
use AzGuard\Tests\Fixtures\Parity\Verdict;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

/*
 * Gap fill of the comparison x invariant matrix (artifacts/evidence/P3.4/gap-matrix.md): the cells the parity suite
 * did not cover, on the same seeded world of ParityWorld. A failure message names its seed.
 *   I1  an unrelated grant does not change any decision of scalar, batch or SQL;
 *   I6  the pages of a visibility query are exactly the unpaged set, without repeats;
 *   model subject = SubjectRef subject for the SQL visibility of a list.
 */

beforeEach(fn () => CrmWorld::seed());

afterEach(function (): void {
    CrmWorld::resetRuntime();
    Carbon::setTestNow();
    Relation::morphMap([], false);
});

const GAP_SEEDS = [1, 2, 3];

/** @return list<ParityCase> */
function gapCases(int $seed): array
{
    return iterator_to_array(ParityWorld::decisionCases($seed), false);
}

/** @return array<string, list<string>> the verdicts of every path per case id */
function gapVerdicts(array $results): array
{
    $verdicts = [];

    foreach ($results as $id => $paths) {
        foreach ($paths as $path => $verdict) {
            $verdicts[$id][$path] = $verdict instanceof Verdict ? ($verdict->allowed ? 'allow' : 'deny') : 'unsupported';
        }
    }

    return $verdicts;
}

it('I1 an unrelated grant changes no decision on scalar, batch or SQL', function (): void {
    foreach (GAP_SEEDS as $seed) {
        ParityWorld::write($seed);
        ParityWorld::compile($seed, false);
        $cases = gapCases($seed);
        $before = gapVerdicts((new ParityRunner)->decisions($cases));
        // Both verdicts occur: the comparison is not vacuously all-deny.
        $flat = array_merge(...array_values(array_map(array_values(...), $before)));
        expect($flat)->toContain('allow')->toContain('deny');

        // Grants of a permission no case asks about, for every subject of the world, in both tenants and the global one.
        CrmWorld::storage()->mutate(ParityWorld::PANELS, static function (StorageMutation $mutation): void {
            foreach (ParityWorld::PANELS as $panel) {
                foreach (ParityWorld::SUBJECTS as $subject) {
                    foreach ([TenantRef::of('crm.organization', 1), TenantRef::of('crm.organization', 2), TenantRef::global()] as $tenant) {
                        $scope = AccessScope::in($tenant);
                        $mutation->table('permission_grants')->insert([
                            'panel' => $panel, 'tenant_key' => $scope->tenant->key(), 'tenant_type' => $scope->tenant->type(), 'tenant_id' => $scope->tenant->id(),
                            'context_key' => $scope->context->key(), 'context_type' => $scope->context->type(), 'context_id' => $scope->context->id(),
                            'subject_type' => 'crm.user', 'subject_id' => (string) $subject, 'permission' => 'clients.unrelated_gap_probe',
                            'origin' => 'gap-'.$panel.'-'.$subject, 'expires_at' => null, 'meta' => null,
                        ]);
                    }
                }
                $mutation->touch($panel);
            }
        });
        ParityWorld::compile($seed, false);

        expect(gapVerdicts((new ParityRunner)->decisions($cases)))->toBe($before, "I1 seed=$seed");
    }
});

it('I6 the pages of a visibility query are the unpaged set without repeats', function (): void {
    $checked = 0;

    foreach (GAP_SEEDS as $seed) {
        ParityWorld::write($seed);
        ParityWorld::compile($seed, false);
        $registry = app(PanelRegistry::class);

        foreach (ParityWorld::PANELS as $panel) {
            foreach (ParityWorld::SUBJECTS as $subject) {
                foreach (ParityWorld::PERMISSIONS as $permission) {
                    foreach (ParityWorld::TENANTS as $tenant) {
                        $scope = AccessScope::in(TenantRef::of('crm.organization', $tenant));
                        $list = static fn () => app(Authorizer::class)->visibleTo($registry->get($panel), Client::query(), SubjectRef::of('crm.user', $subject), $permission, $scope)->orderBy('id');

                        try {
                            $all = array_map(intval(...), $list()->pluck('id')->all());
                        } catch (VisibilityNotSupportedException) {
                            continue;
                        }
                        $paged = [];

                        for ($page = 1; $page <= count(ParityWorld::CLIENTS); $page++) {
                            $paged = [...$paged, ...array_map(intval(...), $list()->forPage($page, 2)->pluck('id')->all())];
                        }
                        $label = "I6 seed=$seed panel=$panel subject=$subject permission=$permission tenant=$tenant";

                        expect($paged)->toBe($all, $label)->and(array_unique($paged))->toBe($paged, $label);
                        $checked++;
                    }
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

it('a model subject and a SubjectRef subject see the same list', function (): void {
    $checked = 0;

    foreach (GAP_SEEDS as $seed) {
        ParityWorld::write($seed);
        ParityWorld::compile($seed, false);
        $registry = app(PanelRegistry::class);

        foreach (ParityWorld::PANELS as $panel) {
            foreach (ParityWorld::SUBJECTS as $subject) {
                foreach (ParityWorld::PERMISSIONS as $permission) {
                    foreach (ParityWorld::TENANTS as $tenant) {
                        $scope = AccessScope::in(TenantRef::of('crm.organization', $tenant));
                        $ids = static fn (User|SubjectRef $who): array => array_map(intval(...), app(Authorizer::class)
                            ->visibleTo($registry->get($panel), Client::query(), $who, $permission, $scope)->orderBy('id')->pluck('id')->all());

                        try {
                            $byRef = $ids(SubjectRef::of('crm.user', $subject));
                        } catch (VisibilityNotSupportedException) {
                            continue;
                        }

                        expect($ids(User::query()->findOrFail($subject)))->toBe($byRef, "seed=$seed panel=$panel subject=$subject permission=$permission tenant=$tenant");
                        $checked++;
                    }
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0);
});

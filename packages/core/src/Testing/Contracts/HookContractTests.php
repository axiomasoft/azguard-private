<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Attributes\Spi;
use AzGuard\Attributes\SpiKind;
use AzGuard\Changes\Change;
use AzGuard\Changes\ChangeResult;
use AzGuard\Contracts\Authorization\AfterHook;
use AzGuard\Contracts\Authorization\BeforeHook;
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Contracts\Changes\ChangePipe;
use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Exceptions\DefinitionException;
use AzGuard\Facades\AzGuard;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\BeforeResult;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Panels\PanelBuilder;
use AzGuard\Sources\Database\DatabaseSource;
use AzGuard\Testing\FakeSource;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use UnitEnum;

/**
 * For the authors of check hooks and change pipes: what they guarantee, checked against the real engine.
 *
 * Use the trait in a test case that boots a Laravel application with the AzGuard tables migrated, and implement the
 * factories of the parts you wrote; a part you have none of is `null` and its tests pass without checking anything.
 *
 * - every hook and pipe fits the contract of its slot: a closure has its parameter and return types, so the panel
 *   compiles;
 * - a `before` hook answers with a `BeforeResult`, is repeatable and writes nothing;
 * - an `after` hook observes: it writes nothing and the decision stays what it was;
 * - a pipe writes nothing itself: what reaches the database goes through the writer it hands the change to;
 * - a change that is cancelled after the pipe left nothing behind in any table.
 */
#[Spi(kind: SpiKind::Call, since: '0.7')]
trait HookContractTests
{
    /**
     * A `before` hook as the panel takes it (a `BeforeHook`, its class name or a closure of its signature), or null.
     *
     * @return BeforeHook|class-string<BeforeHook>|Closure(AccessRequest, EvaluationContext): BeforeResult|null
     */
    protected function azguardBeforeHook(): BeforeHook|Closure|string|null
    {
        return null;
    }

    /**
     * An `after` hook as the panel takes it (an `AfterHook`, its class name or a closure of its signature), or null.
     *
     * @return AfterHook|class-string<AfterHook>|Closure(AccessRequest, EvaluationContext, Decision): void|null
     */
    protected function azguardAfterHook(): AfterHook|Closure|string|null
    {
        return null;
    }

    /**
     * A new changing pipe (a `ChangePipe`, its class name or a closure of its signature), or null.
     *
     * @return ChangePipe|class-string<ChangePipe>|Closure(Change, Closure(Change): ChangeResult): ChangeResult|null
     */
    protected function azguardChangePipe(): ChangePipe|Closure|string|null
    {
        return null;
    }

    /** The subject the suite asks about and grants to. */
    protected function azguardSubject(): Model
    {
        return ContractWorld::subject(1);
    }

    /**
     * The permissions of the panel `contract` the suite decides.
     *
     * @return list<string|UnitEnum>
     */
    protected function azguardPermissions(): array
    {
        return [ContractPermission::View, ContractPermission::Edit];
    }

    #[Test]
    public function hooksAndPipeFitTheirContracts(): void
    {
        $before = $this->azguardBeforeHook();
        $after = $this->azguardAfterHook();
        $pipe = $this->azguardChangePipe();

        if ($before === null && $after === null && $pipe === null) {
            $this->assertNull($before, 'There is no hook or pipe to check.');

            return;
        }

        try {
            $panel = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->before($before === null ? [] : [$before])->after($after === null ? [] : [$after])->changing($pipe === null ? [] : [$pipe]));
        } catch (DefinitionException $error) {
            $this->fail('The panel does not accept the hook or pipe: '.$error->getMessage());
        }

        $this->assertSame([$before === null ? 0 : 1, $after === null ? 0 : 1, $pipe === null ? 0 : 1], [count($panel->before()), count($panel->after()), count($panel->changing())]);
    }

    #[Test]
    public function afterHookObservesAndWritesNothing(): void
    {
        $hook = $this->azguardAfterHook();

        if ($hook === null) {
            $this->assertNull($hook, 'There is no after hook to check.');

            return;
        }
        $source = new FakeSource;
        $plain = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source]));
        $observed = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source])->after([$hook]));
        $subject = $this->azguardSubject();
        $expected = [];
        $actual = [];

        $writes = ContractWorld::writesDuring(function () use ($plain, $observed, $subject, &$expected, &$actual): void {
            foreach ($this->azguardPermissions() as $position => $permission) {
                $expected[$position] = ContractWorld::decide($plain, $subject, $permission);
                $actual[$position] = ContractWorld::decide($observed, $subject, $permission);
            }
        });

        $this->assertSame([], $writes, 'The after hook wrote while it observed: '.implode('; ', $writes));

        foreach ($expected as $position => $decision) {
            $this->assertSame($decision->effect, $actual[$position]->effect, 'The after hook changed the effect of a decision.');
            $this->assertSame($decision->reason, $actual[$position]->reason, 'The after hook changed the reason of a decision.');
        }
    }

    #[Test]
    public function beforeHookAnswersWithABeforeResultAndWritesNothing(): void
    {
        $hook = $this->azguardBeforeHook();

        if ($hook === null) {
            $this->assertNull($hook, 'There is no before hook to check.');

            return;
        }
        $source = new FakeSource;
        $panel = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([$source])->before([$hook]));
        $subject = $this->azguardSubject();
        $decisions = [];

        $writes = ContractWorld::writesDuring(function () use ($panel, $subject, &$decisions): void {
            foreach ($this->azguardPermissions() as $position => $permission) {
                $decisions[$position] = [ContractWorld::decide($panel, $subject, $permission), ContractWorld::decide($panel, $subject, $permission)];
            }
        });

        $this->assertSame([], $writes, 'The before hook wrote while it decided: '.implode('; ', $writes));

        foreach ($decisions as [$first, $second]) {
            $this->assertNotSame(DecisionReason::HookError, $first->reason, 'The before hook threw or did not return a BeforeResult.');
            $this->assertSame($first->reason, $second->reason, 'The before hook answers differently to the same request.');
            $this->assertSame($first->effect, $second->effect, 'The before hook changes the effect between two identical requests.');
        }
    }

    #[Test]
    public function pipeWritesNothingBesidesTheWriter(): void
    {
        $pipe = $this->azguardChangePipe();

        if ($pipe === null) {
            $this->assertNull($pipe, 'There is no pipe to check.');

            return;
        }
        $observed = new ObservedPipe($pipe);
        $panel = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([DatabaseSource::make()])->changing([$observed]));
        $subject = $this->azguardSubject();

        try {
            AzGuard::actingAs('contract', fn () => AzGuard::panel($panel->id())->for($subject)->grantPermission(ContractPermission::View));
        } catch (ChangeCancelledException) {
            // A pipe may cancel the change; it still must not have written.
        }

        $this->assertSame([], $observed->outside, 'The pipe wrote to the database itself, around the writer: '.implode('; ', $observed->outside));
    }

    #[Test]
    public function cancelledChangeLeavesNoTrace(): void
    {
        $pipe = $this->azguardChangePipe();

        if ($pipe === null) {
            $this->assertNull($pipe, 'There is no pipe to check.');

            return;
        }
        $panel = ContractWorld::panel(static fn (PanelBuilder $builder): PanelBuilder => $builder->permissions([DatabaseSource::make()])->changing([$pipe, new CancellingPipe]));
        $subject = $this->azguardSubject();
        $before = ContractWorld::rowCounts();

        try {
            AzGuard::actingAs('contract', fn () => AzGuard::panel($panel->id())->for($subject)->grantPermission(ContractPermission::View));
            $this->fail('The change was not cancelled by the last pipe: the pipe of the author does not pass the change on.');
        } catch (ChangeCancelledException) {
            // The expected end.
        } catch (Throwable $error) {
            $this->fail('The cancelled change ended with '.$error::class.': '.$error->getMessage());
        }

        $this->assertSame($before, ContractWorld::rowCounts(), 'A cancelled change left rows behind.');
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Testing\Contracts;

use AzGuard\Exceptions\ChangeCancelledException;
use AzGuard\Facades\AzGuard;
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
 * - a `before` hook answers with a `BeforeResult`, is repeatable and writes nothing;
 * - a pipe writes nothing itself: what reaches the database goes through the writer it hands the change to;
 * - a change that is cancelled after the pipe left nothing behind in any table.
 *
 * @api
 */
trait HookContractTests
{
    /** A `before` hook as the panel takes it (a closure or the name of an invokable class), or null. */
    protected function azguardBeforeHook(): Closure|string|null
    {
        return null;
    }

    /** A new changing pipe (a closure, an object with `handle()` or a class name), or null. */
    protected function azguardChangePipe(): object|string|null
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

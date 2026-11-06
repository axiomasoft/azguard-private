<?php

declare(strict_types=1);

namespace App\Providers;

use AzGuard\Authorization\Internal\Authorizer;
use AzGuard\Authorization\Internal\Cache\PermissionSetCache;
use AzGuard\Changes\Internal\ActingActor;
use AzGuard\Context\Internal\CurrentContext;
use AzGuard\Context\Internal\WithinContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\PermissionKey;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Internal\CurrentPanel;
use AzGuard\Panels\Internal\PanelRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use ReflectionProperty;
use RuntimeException;

final class WorkerProbe
{
    public static function run(int $number, bool $job = false): array
    {
        $panel = app(PanelRegistry::class)->get($number % 2 ? 'worker-a' : 'worker-b');
        $contexts = app(CurrentContext::class);
        $current = app(CurrentPanel::class);
        $actors = app(ActingActor::class);
        $memo = app(PermissionSetCache::class);
        $counts = [];
        foreach (['sets' => 'memo', 'states' => 'states', 'panels' => 'panels'] as $property => $label) {
            $counts[$label] = count((new ReflectionProperty($memo, $property))->getValue($memo));
        }

        if ($current->get() !== null || $current->rejected() !== null || $contexts->get($panel) !== null
            || $actors->current($panel)?->id !== null || $counts !== ['memo' => 0, 'states' => 0, 'panels' => 0]) {
            throw new RuntimeException('lifecycle retained state');
        }

        if ($job && request()->headers->has('X-Worker-Marker')) {
            throw new RuntimeException('job retained a previous request');
        }

        if (! $job && request()->header('X-Worker-Marker') !== (string) $number) {
            throw new RuntimeException('HTTP request marker was not replaced');
        }
        $case = $number % 5;
        $subject = [1, 1, 2, 3, 4][$case];
        $tenant = $case === 1 ? 'B' : 'A';
        $scope = AccessScope::in(TenantRef::of('org', $tenant));
        $actor = ActorRef::of('user', 1000 + $number);
        $request = AccessRequest::for(SubjectRef::of('user', $subject), PermissionKey::of($panel->id(), 'orders.view'));
        $decision = $current->run($panel, fn () => app(WithinContext::class)->run($panel, $scope,
            fn () => $actors->run($actor, function () use ($panel, $request, $actor, $actors, $contexts, $scope) {
                if ($actors->current($panel) !== $actor || $contexts->get($panel) !== $scope) {
                    throw new RuntimeException('explicit context or actor missing');
                }

                try {
                    app(WithinContext::class)->run($panel, AccessScope::in(TenantRef::of('org', 'B')), function () use ($actors): void {
                        $actors->run(ActorRef::of('user', 999), static function (): void {
                            throw new RuntimeException('nested');
                        });
                    });

                    throw new RuntimeException('nested exception missing');
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== 'nested') {
                        throw $exception;
                    }
                }

                if ($contexts->get($panel) !== $scope || $actors->current($panel) !== $actor) {
                    throw new RuntimeException('nested context or actor was not restored');
                }

                return app(Authorizer::class)->decide($panel, $request);
            })));

        if ($current->get() !== null || $current->rejected() !== null || $contexts->get($panel) !== null || $actors->current($panel)?->id !== null) {
            throw new RuntimeException('finally cleanup failed');
        }
        $memoEntries = count((new ReflectionProperty($memo, 'sets'))->getValue($memo));

        if ($case === 0 && $memoEntries === 0) {
            throw new RuntimeException('successful decision did not populate the lifecycle memo');
        }
        $status = file_get_contents('/proc/'.getmypid().'/status');

        if ($status === false || preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $match) !== 1) {
            throw new RuntimeException('worker RSS unavailable');
        }

        return ['request' => $number, 'pid' => getmypid(), 'rss' => (int) $match[1] * 1024, 'counts' => $counts,
            'allowed' => $decision->allowed(), 'reason' => $decision->reason->value, 'tenant' => $decision->scope->tenant->id(),
            'panel' => $panel->id(), 'subject' => $subject, 'actor' => $actor->id, 'memo_after' => $memoEntries];
    }
}

final class WorkerJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $number) {}

    public function handle(): void
    {
        file_put_contents((string) getenv('WORKER_JOB_RESULTS'), json_encode(WorkerProbe::run($this->number, true), JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}

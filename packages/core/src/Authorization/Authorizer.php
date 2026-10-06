<?php

declare(strict_types=1);

namespace AzGuard\Authorization;

use AzGuard\Authorization\Pipeline\AccessPipeline;
use AzGuard\Authorization\Pipeline\Stages\AuthorityStage;
use AzGuard\Authorization\Pipeline\Stages\PrepareStage;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Exceptions\RecursionDetectedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\AssignmentScopeRef;
use AzGuard\Kernel\Identity\IdentityCodec;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use Fiber;
use Illuminate\Support\Carbon;
use Throwable;

final class Authorizer
{
    /** @var array<string,true> */
    private array $active = [];

    public function __construct(private readonly PrepareStage $prepare, private readonly AccessPipeline $pipeline, private readonly AuthorityStage $authority) {}

    public function isSuperAdmin(Panel $panel, SubjectRef $subject, AccessScope $scope): bool
    {
        $key = IdentityCodec::compose([$panel->id(), $subject->type(), $subject->id(), 'superadmin.qualify', $scope->tenant->key(), $scope->context->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);

        if (isset($this->active[$key])) {
            throw new RecursionDetectedException('Recursive super-admin qualification of the same panel, subject and scope.');
        }
        $this->active[$key] = true;

        try {
            $now = Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace(false);
                [$request, $catalog, $frame, $denial] = $this->prepare->prepareSuperAdmin($panel, $subject, $scope, $trace, $now);

                if ($denial !== null) {
                    return false;
                }

                try {
                    [$frame, , $denial] = $this->authority->qualify($request, $frame, $catalog, $trace);
                    $frame = $frame->readAttempt?->confirm($frame) ?? $frame;

                    return $denial === null && $frame->qualifiedSuperAdmin;
                } catch (ReadAttemptChanged) {
                    if ($attempt === 2) {
                        return false;
                    }
                } catch (Throwable $error) {
                    $trace->error('state', 'source_error', 'sources', $error);

                    return false;
                }
            }

        } finally {
            unset($this->active[$key]);
        }
    }

    public function decide(Panel $panel, AccessRequest $request, ?ActorRef $actor = null): Decision
    {
        $key = IdentityCodec::compose([$panel->id(), $request->subject()->type(), $request->subject()->id(), $request->permission()->full(), ($request->tenant() ?? TenantRef::global())->key(), ($request->context() ?? AssignmentScopeRef::global())->key(), (string) (Fiber::getCurrent() === null ? 0 : spl_object_id(Fiber::getCurrent()))]);

        if (isset($this->active[$key])) {
            throw new RecursionDetectedException('Recursive authorization of the same panel, subject, permission and scope.');
        }
        $this->active[$key] = true;

        try {
            $now = Carbon::now('UTC')->toDateTimeImmutable();
            for ($attempt = 0; ; $attempt++) {
                $trace = new Trace($request->isTraced());

                try {
                    [$catalog,$definition,$frame,$denial] = $this->prepare->prepare($panel, $request, $actor, $trace, $now);

                    return $this->pipeline->evaluate($request, $frame, $catalog, $definition, $trace, $denial);
                } catch (ReadAttemptChanged $changed) {
                    if ($attempt === 2) {
                        return $this->pipeline->inconsistent($request, $changed->frame, $trace);
                    }
                }
            }
        } finally {
            unset($this->active[$key]);
        }
    }
}

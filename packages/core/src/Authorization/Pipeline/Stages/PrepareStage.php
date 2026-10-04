<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline\Stages;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Authorization\ModelSubjectResolver;
use AzGuard\Authorization\Pipeline\Trace;
use AzGuard\Catalog\PanelCatalog;
use AzGuard\Catalog\PermissionDefinition;
use AzGuard\Exceptions\SubjectNotAcceptedException;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\CodeStateToken;
use AzGuard\Kernel\Decision\Decision;
use AzGuard\Kernel\Decision\DecisionReason;
use AzGuard\Kernel\Identity\AccessScope;
use AzGuard\Kernel\Identity\ActorRef;
use AzGuard\Kernel\Identity\SubjectRef;
use AzGuard\Kernel\Identity\TenantRef;
use AzGuard\Panels\Panel;
use AzGuard\Panels\PanelRegistry;
use Illuminate\Support\Carbon;
use Throwable;

final readonly class PrepareStage
{
    public function __construct(private PanelRegistry $registry, private ModelSubjectResolver $resolver) {}

    /** @return array{PanelCatalog,PermissionDefinition,EvaluationFrame,?Decision} */
    public function prepare(Panel $panel, AccessRequest $request, ?ActorRef $actor, Trace $trace): array
    {
        if (! $panel->accepts($request->subject())) {
            throw new SubjectNotAcceptedException('Panel '.$panel->id().' does not accept subject '.$request->subject()->type().'.');
        }
        $catalog = $this->registry->catalog($panel->id());
        $definition = $catalog->get($request->permission());
        $actor ??= ActorRef::of($request->subject()->type(), $request->subject()->id());
        $now = Carbon::now('UTC')->toDateTimeImmutable();
        $subject = $actorModel = null;
        $error = null;

        try {
            $subject = $this->resolver->resolve($panel, $request->subject());
            $actorModel = $actor->id === null ? null : ($actor->type === $request->subject()->type() && $actor->id === $request->subject()->id() ? $subject : $this->resolver->resolve($panel, SubjectRef::of($actor->type, $actor->id)));
        } catch (Throwable $caught) {
            $error = $caught;
            $trace->error('prepare', 'source_error', ModelSubjectResolver::class, $caught);
        }
        $frame = new EvaluationFrame(
            selectedPanel: $panel, selectedScope: AccessScope::in($request->tenant() ?? TenantRef::global(), $request->context()),
            token: CodeStateToken::of($panel->id(), $this->registry->buildId(), $this->registry->fingerprint($panel->id())),
            decisionNow: $now, selectedActor: $actor, subject: $subject, actorSubject: $actorModel, selectedResource: $request->resource(),
        );

        return [$catalog, $definition, $frame, $error === null ? null : Decision::deny(DecisionReason::SourceError, $frame->state(), $frame->scope(), ModelSubjectResolver::class)];
    }
}

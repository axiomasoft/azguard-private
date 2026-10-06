<?php

declare(strict_types=1);

namespace AzGuard\Authorization\Pipeline;

use AzGuard\Authorization\EvaluationFrame;
use AzGuard\Kernel\Decision\Grant;
use AzGuard\Kernel\Decision\RoleContribution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Trace
{
    /** @var list<array<string, mixed>> */
    private array $steps = [];

    /** @var array{class: string, id: int|string|null}|null */
    private ?array $resource = null;

    /** @var array<string, mixed> */
    private array $qualification = [];

    public function __construct(private readonly bool $enabled = false, public readonly bool $diagnostic = false) {}

    /** @param array<string, mixed> $detail */
    public function record(string $stage, string $result, ?string $component = null, ?Throwable $error = null, array $detail = [], ?string $outcome = null): void
    {
        if ($this->enabled) {
            if (in_array($stage, ['contribution', 'condition', 'filter'], true)) {
                $detail = [...$this->qualification, ...$detail];
            }
            $outcome ??= $error !== null ? 'error' : match ($result) {
                'skipped', 'not_applicable', 'exempt' => 'skipped',
                'Deny', 'restricted', 'condition_false', 'expired', 'not_granted',
                'global_role_not_allowed', 'former_role_key', 'unknown_role', 'not_grantable_role',
                'role_scope_not_accepted', 'scope_ineligible' => 'deny',
                default => 'pass',
            };
            $this->steps[] = ['stage' => $stage, 'component' => $component, 'outcome' => $outcome,
                'detail' => $error === null ? $detail : [...$detail, 'exception' => $error::class, 'message' => $error->getMessage()],
                'result' => $result, 'exception' => $error === null ? null : $error::class];
        }
    }

    public function inputs(EvaluationFrame $frame): void
    {
        if (! $this->enabled) {
            return;
        }
        $resource = $frame->resource();
        $this->resource = $resource === null ? null : ['class' => $resource::class,
            'id' => $resource instanceof Model ? $resource->getKey() : null];
    }

    /** @return array{class: string, id: int|string|null}|null */
    public function resource(): ?array
    {
        return $this->resource;
    }

    public function contribution(Grant|RoleContribution $item, string $component): void
    {
        if (! $this->enabled) {
            return;
        }
        $this->record('contribution', 'observed', $component, detail: [
            'source' => $item->source, 'origin' => $item->origin, 'role' => $item->role?->full(),
            'permission' => $item instanceof Grant ? $item->pattern->full() : null,
            'tenant' => $item->scope->tenant->key(), 'context' => $item->scope->context->key(),
            'expires_at' => $item->expiresAt?->format(DATE_ATOM), 'fields' => $item->fields(),
        ], outcome: 'contribution');
    }

    public function qualifying(Grant|RoleContribution $item): void
    {
        if ($this->enabled) {
            $this->qualification = ['source' => $item->source, 'origin' => $item->origin,
                'role' => $item->role?->full(), 'permission' => $item instanceof Grant ? $item->pattern->full() : null,
                'tenant' => $item->scope->tenant->key(), 'context' => $item->scope->context->key(),
                'expires_at' => $item->expiresAt?->format(DATE_ATOM), 'fields' => $item->fields()];
        }
    }

    public function finish(): void
    {
        if (! $this->enabled) {
            return;
        }
        foreach (['prepare', 'boundary', 'before', 'sources', 'condition', 'filter', 'superadmin', 'membership', 'policy', 'authority', 'restriction', 'state', 'after'] as $stage) {
            if (! in_array($stage, array_column($this->steps, 'stage'), true)) {
                $this->record($stage, 'skipped');
            }
        }
    }

    public function error(string $stage, string $reason, string $component, Throwable $error): void
    {
        $this->record($stage, $reason, $component, $error);
        Log::warning('AzGuard evaluation failed.', ['component' => $component, 'reason' => $reason, 'exception' => $error::class]);
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->steps;
    }
}

# Decision observability

The default Gate, middleware and Filament paths refuse failures with a generic 403. A refusal is not necessarily an outage: `Decision::failure()` is `null` for an ordinary denial, `transient` for `source_error` or `consistency_error`, and `contract` for `policy_error`, `hook_error`, `restriction_error`, `condition_error` or `context_filter_error`. Observe decisions independently of the HTTP response.

An application can register an after hook for each panel. This reference adapter uses only bounded labels: the outcome, the closed reason enum, and a source label selected from a fixed application-owned map. The component is not necessarily a source, so unknown values become `other`. Replace `Metrics::increment` with the host's counter implementation.

```php
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use Illuminate\Support\Facades\Log;

->after(function (AccessRequest $request, EvaluationContext $context, Decision $decision): void {
    $sources = [App\Authorization\DatabaseSource::class => 'database']; // fixed at deployment
    $outcome = $decision->failure()?->value ?? ($decision->allowed() ? 'allow' : 'deny');
    $labels = [
        'outcome' => $outcome,
        'reason' => $decision->reason->value,
        'source' => $sources[$decision->component ?? ''] ?? 'other',
    ];

    try {
        Metrics::increment('azguard_decisions_total', $labels);
    } catch (Throwable $error) {
        // Do not log the exception message or request. Correlation IDs belong in logs only.
        Log::warning('Decision telemetry unavailable.', ['exception' => $error::class]);
    }
})
```

Never use the request, subject, tenant or resource IDs, grant fields, exception messages, arbitrary component names, or correlation IDs as metric labels. Do not log identities or grant fields by default. If correlation is needed, add a host-generated correlation ID to a protected log entry, not to the counter. `explain` and traced decision events can contain sensitive details and need separate access and retention controls. After hooks observe the decision; an exception in an observer is logged and does not change it. A failure before the hook can prevent observation, so monitor the telemetry pipeline itself too.

For Prometheus, expose `azguard_decisions_total{outcome,reason,source}` as a counter. For OpenTelemetry, map the same dimensions to attributes on a counter; the SDK and exporter are optional host dependencies, not AzGuard runtime dependencies. Example alerts (choose thresholds for your traffic):

```promql
sum(increase(azguard_decisions_total{outcome="contract"}[5m])) > 0
sum(rate(azguard_decisions_total{outcome="transient"}[5m])) > 0
```

To opt an API endpoint into the reference HTTP mapping, call `DecisionResponder::authorize($decision)` after deciding. It returns normally for allow, throws a generic 403 for ordinary denial, 503 with `Retry-After` for transient failure, and 500 for contract failure. Render exceptions without exposing their internal reason or an explanation; production debug output must be disabled. The exception's reason is available to trusted server-side reporting. This opt-in does not alter Gate or Filament defaults.

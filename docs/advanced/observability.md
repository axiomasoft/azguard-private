# Decision observability

The default Gate, middleware and Filament paths refuse failures with a generic 403. A refusal is not necessarily an outage: `Decision::failure()` is `null` for an ordinary denial, `transient` for `source_error` or `consistency_error`, and `contract` for `policy_error`, `hook_error`, `restriction_error`, `condition_error` or `context_filter_error`. Observe decisions independently of the HTTP response.

An application can register an after hook for each panel. This reference adapter uses only bounded labels: the outcome, the closed reason enum, and a source label selected from a fixed application-owned map. The component is not necessarily a source, so unknown values become `other`. Replace `Metrics::increment` with the host's counter implementation.

```php
use AzGuard\Contracts\Authorization\EvaluationContext;
use AzGuard\Kernel\Decision\AccessRequest;
use AzGuard\Kernel\Decision\Decision;
use Illuminate\Support\Facades\Log;

->after(function (AccessRequest $request, EvaluationContext $context, Decision $decision): void {
    $sources = [AzGuard\Sources\Database\DatabaseSource::class => 'database']; // fixed at deployment
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

## Operator monitoring acceptance

The application owner installs the hook on every relevant panel and exposes the host's counter/exporter. The
monitoring owner configures scraping, recording/alert rules, routing and an on-call receiver. This page does not
install any of them. Keep `outcome` in `allow|deny|transient|contract`, `reason` in the closed decision-reason enum,
and `source` in a reviewed fixed map plus `other`. Correlation IDs belong only in protected, redacted logs with
host retention controls. Do not log `access.decided` or `explain` payloads wholesale: they can include identities,
scope, source topology and trace details even when signing secrets are redacted.

Before admitting traffic, use a disposable test identity and the same installed adapter in staging to cause one
source/transient failure and one contract failure. Confirm both deny, the default HTTP/Filament response stays
generic 403, and internal failure reason/component/exception text do not appear in the body. Where the host opted
into `DecisionResponder`, verify its chosen 503/500 behavior separately. Never disable a production source or grant
test authority in production to generate these events. The monitoring owner can use a separately approved canary
or monitoring-system test notification for production routing.

For each kind, capture a counter baseline, inject the staging event, wait at least two scrape intervals, and query
the actual monitoring API over a window covering that event. With `PROMETHEUS_URL` set to the approved read-only
monitoring endpoint, run from a repository checkout (the PHP verifier is a repository fixture, not a Composer API):

```bash
set -euo pipefail
curl --fail --silent --show-error --connect-timeout 5 --max-time 15 --get "${PROMETHEUS_URL:?}/api/v1/query" \
  --data-urlencode 'query=sum(increase(azguard_decisions_total{outcome="contract"}[5m]))' \
  --output contract-query.json
php tests/Fixtures/Operations/monitoring-check.php < contract-query.json
curl --fail --silent --show-error --connect-timeout 5 --max-time 15 --get "$PROMETHEUS_URL/api/v1/query" \
  --data-urlencode 'query=sum(increase(azguard_decisions_total{outcome="transient"}[5m]))' \
  --output transient-query.json
php tests/Fixtures/Operations/monitoring-check.php < transient-query.json
```

Use the host's approved credential delivery, CA and bounded curl timeouts; do not put bearer tokens in a shared
command transcript. Stop if curl fails; never validate an old output file after a failed download. The verifier
requires a successful vector response with exactly one finite positive sample; errors, missing series, NaN and
zero fail. The drill tests those negative cases with synthetic API responses, not a deployed Prometheus service.
A positive query proves an observed signal, not alert routing or receipt: save the alert rule revision, firing
alert and received test notification timestamps, receiver/owner and redacted evidence link separately.

Configure the two alert expressions above plus scrape/exporter absence and telemetry delivery failure alerts.
Ordinary denials alone must not page as source outages. Investigate `contract` as an application/plugin defect and
`transient` as source availability or consistency churn. Keep access denied during investigation, check primary
connectivity and deployment/config changes, and use protected correlation logs without retrying with weaker scopes
or an authorization bypass. Monitor Redis latency/evictions and database lock waits through host instrumentation.

The deployment gate in the repository's `RELEASING.md` (“Production operations acceptance”) requires an application and
monitoring owner plus actual notification evidence. Markdown, a local fixture, Doctor exit 0 and a generic 403 are
each insufficient to claim a configured production alert.

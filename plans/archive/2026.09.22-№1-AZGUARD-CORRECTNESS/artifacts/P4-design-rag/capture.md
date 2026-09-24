# Perplexity capture — P4 — 2026-09-23

**Tool:** `perplexity.search` (ordinary search)
**Capture kind:** response text returned to the design session
**Normative status:** non-normative retrieval lead; load-bearing claims require direct primary-source or repository verification.

## Query

Use only primary sources and provide a concise evidence ledger with URLs, no quotations. Laravel 13 scoped container bindings in HTTP requests and queue jobs, lifecycle reset boundaries and absence of fiber-local guarantees; Gate::before behavior when returning null; middleware finally-style restoration patterns if officially documented. Explicitly mark inference versus documented contract.

## Response

Laravel 13 documents scoped as one resolved instance per Laravel request/job lifecycle. It explicitly says scoped instances are flushed when Octane begins a new HTTP request and when a queue worker begins a new job; it does not document any fiber-local or coroutine-local isolation guarantee.

## Contract vs inference


## Scoped binding pattern

```
use App\Contracts\ExecutionContext; use App\Support\ExecutionContext; use Illuminate\Contracts\Foundation\Application; $this->app->scoped( ExecutionContext::class, fn (Application $app) => new ExecutionContext() );
```
Or via the attribute:
```
use Illuminate\Container\Attributes\Scoped; #[Scoped] final class ExecutionContext { // Mutable state is bounded by the Laravel request/job lifecycle, // not documented as fiber-local. }
```
Laravel documents both forms and defines the lifetime as once per request/job lifecycle.

## Practical consequence

A good use is a mutable request/job-wide accumulator, correlation context, or resolved tenant context only if all work belongs to one request/job execution. A bad use is relying on it to distinguish simultaneous async branches/fibers inside that same execution. That latter limitation is an inference from the absence of a fiber-local contract, rather than an explicit Laravel prohibition.

## Gate::before and null

Gate::before() runs before other authorization checks. Its result is decisive only if it is non-null:
```
Gate::before(function (User $user, string
```

Sources:
1. [laravel](https://laravel.com/docs/13.x/container)


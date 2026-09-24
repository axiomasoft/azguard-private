# Дизайн P4 — panel lifecycle и validation boundaries

**Статус:** нормативный dossier для P4.1–P4.2.

## 0. Инварианты

1. Boot-time panel registry долгоживущий; current panel request/job-scoped.
2. Nested panel scope всегда восстанавливает exact previous value в `finally`.
3. Existing public manager/Facade/custom-manager contract сохраняется.
4. Strictness opt-in on upgrade; empty registry не выключает strict validation.
5. Permission forms нормализуются одной boundary с сохранением documented wildcards/dynamics.
6. AzGuard wildcard применяется только после доказательства ownership ability.
7. Foreign ability возвращает `null`, чтобы Laravel policy продолжила работу.

## 1. Runtime state architecture (P4.1)

```text
singleton AzGuardManager
  registry: panels registered at boot
  currentPanel()/setCurrentPanel()
    -> lazily resolve scoped CurrentPanelState from container

HTTP request / non-sync queue job / Octane request
  -> fresh scoped holder (plus idempotent existing hooks for custom manager)

sync job
  -> preserve enclosing panel per existing contract
```

Manager must not capture holder in singleton constructor. It resolves the current scoped instance
inside accessors. Lifecycle listeners remain for configured custom manager compatibility and are
idempotent for default manager.

`SetCurrentPanel` algorithm: capture previous; set requested; call next; restore previous in
`finally`. Unconditional null breaks nested calls.

Before `HasScopedRoles` global scope calls `Auth::check()`/`user()`, it detects whether queried
model class is an effective configured auth-provider model and returns early. Detection reads auth
configuration/classes without instantiating or authenticating the subject, preventing recursion.

## 2. Final panel resolution (P4.2)

Every path validates the final result, not only explicit input:

```text
explicit -> configured default -> current -> conventional app fallback
                              final ID -> registration check
```

With `strict_panels=true`, any unknown final ID raises a named error, including empty registry.
Configured default validation occurs after providers have registered panels. With strict off,
legacy lenient/debug behavior remains and doctor explains risk.

Independently of registration strictness, the final ID must be at most 128 characters before
any persistence. This matches adjacent panel columns and D14's full MySQL key. A 129-character
explicit/default/current ID fails with a named error even in legacy mode; 128 is accepted.
Document the BC boundary and consumer data repair for existing longer values.

## 3. Permission normalization/grammar

One internal helper accepts string, pure/backed enum and Permission class forms and outputs one
canonical catalog key. It rejects whitespace/empty/dot-empty segments and double panel prefix.
It preserves global `*`, whole-segment `*`/`**`, multi-segment keys and registered dynamic
`{segment}` patterns. It must reuse existing segment matcher instead of parallel regex grammars.

Ownership is exact catalog membership or match of a registered dynamic definition. Prefix alone
is insufficient. Resolver and Authorizer share this matcher.

## 4. Route attribute boundary

`require_permission_attributes=false` is upgrade default. When middleware reaches a controller
action without `CheckPermission` or `SkipGuardCheck`, legacy mode permits current behavior and
doctor warns; opt-in strict mode raises named configuration error and doctor errors. Explicit
`SkipGuardCheck` is not treated as missing metadata.

Diagnostics inspect only relevant AzGuard-middleware routes and tolerate closures/unresolvable
actions. Text and JSON describe the same verdict.

## 5. Gate ownership order

```text
normalize ability
  -> is AzGuard-owned exact/dynamic key?
     no  -> return null (Laravel continues)
     yes -> resolve AzGuard permissions, including wildcard
```

Wildcard before ownership would authorize unrelated Laravel abilities. Exact-only ownership would
break concrete dynamic permissions; catalog+dynamic matcher is the accepted boundary.

## 6. Worked scenarios

- Outer panel A invokes nested middleware B; success or exception restores A, outer completion
  restores null.
- strict configured default `admin` with empty registry fails after boot; legacy mode warns.
- `app.team.{id}.edit` registers a dynamic pattern; concrete `app.team.42.edit` is owned.
- User has global AzGuard `*`; ability `posts.update` is not in catalog, so AzGuard returns null and
  Laravel PostPolicy decides.
- Pure enum and backed enum representing same permission produce the same catalog key.

Examples: `../artifacts/P4-design/examples.md`.

## 7. Failure modes

- Making whole manager scoped and losing boot registry.
- Singleton captures one scoped state forever.
- `finally { setCurrentPanel(null); }` breaks nesting.
- Claiming fibers are isolated by `scoped`.
- Empty registry treated as bypass; strict default validated before providers boot.
- Prefix-only ownership or wildcard before ownership.
- New public VO/API migration when a private helper suffices.

## 8. Validation and mapping

P4.1: nested success/exception, scoped reset with registry persistence, Octane/queue/sync, custom
manager, auth-model recursion. P4.2: explicit/default/current/fallback across strict/legacy and
empty registry; attributes/skip/doctor; grammar parity; owned dynamic and foreign policy fallback.

| Item | Owns |
|:--|:--|
| P4.1 | holder/manager/lifecycle/middleware/auth guard |
| P4.2 | panel validation, grammar, ownership, attributes, diagnostics/docs/review |

---
name: named-arguments
bucket: php
version: 0.1.0
description: "PHP rule: method/function calls use named arguments; exceptions; activate when writing/reviewing calls or when readability/parameter order is mentioned"
risk: read
persona: oss-dev
tags: ["php", "code-style", "readability", "conventions"]
requires: []
produces_for: []
outputs: []
snippets: []
adapters: [claude, cursor, fable]
sha256: ""
---

## Activate when

- Writing/reviewing a method/function call with 2+ arguments.
- User mentions named arguments, call readability, or parameter order.
- Refactoring legacy positional calls.

## Rules

### 1. Default: named arguments

```php
// ✅ Named arguments
$user = User::query()->updateOrCreate(
    attributes: ['email' => $email],
    values: ['name' => $name],
);

$model->save(options: ['touch' => false]);

$collection = $this->addMediaCollection(name: 'avatar')
    ->acceptsMimeTypes(mimeTypes: ['image/jpeg', 'image/png']);
```

```php
// ❌ Positional where intent is unclear
$user = User::query()->updateOrCreate(['email' => $email], ['name' => $name]);
$model->save(['touch' => false]);
```

### 2. Apply to all application-code calls

Object/static methods, framework functions (`config`, `app`, …), Eloquent, builders (Filament, query builder), third-party packages — anywhere the signature has parameter names.

### 3. Exceptions (named not required)

- **Single obvious arg** where the name adds no clarity: `strtolower($s)`, `count($items)`, `$str->trim()`. Naming optional.
- **Built-in PHP functions** with established signatures (`array_map`, `array_filter`, `implode`) — optional (PHP param names sometimes change between versions).
- **Variadic/splat args** (`...$args`) — nothing to name.

### 4. On review

Positional call with 2+ unclear args → request rewrite to named. Don't block on step-3 exceptions.

## Quality checklist

- [ ] Calls with 2+ args use named arguments
- [ ] Eloquent / builders / package calls — named
- [ ] Exceptions applied deliberately (single arg, built-ins, splat)
- [ ] Argument names match the signature (checked against method declaration)

## Links

- `php/laravel` — named-argument examples in Eloquent, relations, DI.
- `php/dependency-injection` — constructors and method injection with named arguments.
- `php/repositories` — DTO commands and store-method calls with named arguments.
- `php/code-style-spatie` (external) — general Laravel/PHP code style this rule fits into.
- `general/naming-conventions` — this rule is about *call* style; naming-conventions is about names of *entities* (classes, methods, fields).
- `php/static-analysis` — pint/rector pipeline that partially cleans up call style.

<!-- ru-source-sha256: 50e08ed5596fffd2b743be75d7314aeb06b07366459b5d2f4a71227b0ffecd63 -->

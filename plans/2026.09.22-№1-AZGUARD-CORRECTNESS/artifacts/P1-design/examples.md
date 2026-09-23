# P1 implementation examples

These examples are normative in semantics and illustrative in exact private names.

## Typed identity

```php
final readonly class SubjectIdentity
{
    private function __construct(
        public string $morphType,
        public string $id,
    ) {}

    public static function fromAuthenticatable(Authenticatable $subject): self
    {
        // For Eloquent subjects use persisted morph contract, not PHP short name.
        return new self($subject->getMorphClass(), (string) $subject->getAuthIdentifier());
    }

    public function digest(): string
    {
        $material = json_encode([2, $this->morphType, $this->id], JSON_THROW_ON_ERROR);

        return /* fixed-length portable digest proven by tests */;
    }
}
```

## Hit-time expiry

```php
$set = $this->requestCache[$key] ?? $this->loadEnvelope($key);

if ($set !== null && ($set->validUntil() === null || $clock->now()->lt($set->validUntil()))) {
    return $set;
}

$fresh = $resolve();

if ($fresh->validUntil() !== null && ! $clock->now()->lt($fresh->validUntil())) {
    return PermissionSet::empty(); // do not cache already-expired state
}

return $this->store($key, $fresh);
```

## Boundary table

| now | deadline | result |
|:--|:--|:--|
| 11:59:59.999999 | 12:00:00 | cached set may be used |
| 12:00:00.000000 | 12:00:00 | miss + recompute |
| 12:00:00.000001 | 12:00:00 | miss + recompute |


# P4 implementation examples

## Nested restoration

```php
$previous = $manager->currentPanel();
$manager->setCurrentPanel($resolved);

try {
    return $next($request);
} finally {
    $manager->setCurrentPanel($previous);
}
```

## Delegated state

```php
public function currentPanel(): ?string
{
    return app(CurrentPanelState::class)->value;
}
```

The singleton must resolve the scoped holder per call, not capture it during construction.

## Gate order

```php
if (! $ownership->matches($ability)) {
    return null;
}

return $authorizer->allowsOwnedAbility($user, $ability);
```


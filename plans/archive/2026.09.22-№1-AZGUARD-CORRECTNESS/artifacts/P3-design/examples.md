# P3 implementation examples

## Config accessor

```php
/** @return class-string<Role> */
public static function roleModel(): string
{
    return self::validatedModelClass('models.role', Role::class);
}
```

## Builder origin

```php
$model = Config::rolePermissionModel();
$query = $model::query(); // table, connection and global scopes come from configured subclass

return $query->join($configuredPivotTable, /* qualified join */);
```

## Filament resource

```php
public static function getModel(): string
{
    return Config::roleModel();
}

public static function getEloquentQuery(): Builder
{
    return parent::getEloquentQuery();
}
```


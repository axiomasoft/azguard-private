# P5 implementation examples

## Role sync classification

| Definition/current DB | Decision |
|:--|:--|
| exact class + canonical name | no-op |
| exact class + legacy unqualified name, canonical free | rename same row |
| exact class appears twice | fail before write |
| canonical name held by DB-only/other class | fail before write |
| no class row and canonical free | create |

## Safe generator plan

```text
validate all arguments and contracts
render every target in memory
classify target: absent / identical / conflicting
verify recognized generated registration carrier
write owned targets atomically
update recognized registration
on failure restore owned preimages; never touch unrelated PHP
```

## Generated policy intent

```php
final class DocumentPolicy
{
    public function view(BackofficeUser $actor, Document $document): bool
    {
        // generated permission check
    }
}
```


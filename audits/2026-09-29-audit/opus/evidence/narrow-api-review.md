# Точечное уточнение public API — 2026-09-30

Scope: замечания к CRM recipe в 00; новое решение [D84](../02-decisions.md#d84).
Runtime пакета не изменялся. Предыдущие evidence/probes описывают свои исторические версии контрактов.

| Узкое место | Исправление |
|---|---|
| exists скрывает lookup, затем tenantOf делает второй query | query(): Builder + BaseAssignmentScope.resolve(ref), одна authoritative record с owner |
| query как setter мешает descriptor query | filter(...) для common/role predicates; query() для fresh structural наборов |
| Context смешивается с ambient context Laravel | ProjectScope, Scopes/, AssignmentScopeDefinition; role scopes()/scopeRequired() |
| Политика связывается по имени метода | обязательный #[Decides(enumCase)]; declared Grants veto проверяется независимо от implementation |
| Переименование префикса ломает application literals | enum case + guard:panel id; resourcePrefix() оформляет external presentation |
| for требует незаметный positional array | for(model: User::class, guard: 'web'); несколько моделей в model:[...] |
| Один enum в двух panels, wrapper и аргумент guard конфликтуют | exact catalogue mapping, explicit ambiguity/mismatch; скрытого переключения нет |
| plugin prefix смешивается с panel prefix | Plugin.prefixed — namespace identity; panel.resourcePrefix — presentation, роли/grants DB не меняются |

Встроенный Eloquent adapter соответствует [Laravel Eloquent](https://laravel.com/docs/13.x/eloquent):
Builder расширяется predicates, запись/ключ берутся из model. Атрибуты методов проверены по
[PHP attribute syntax](https://www.php.net/manual/en/language.attributes.syntax.php); архитектурное правило
обязательного Decides — собственный контракт пакета, не требование Laravel.

Qualification выполнена: validator — 414 local links/anchors, D/V/R/F/C numbering и D84 signatures без ошибок;
git diff --check — pass.
R05/R19/R50/R67 и V78/V81 уточнены как future consumer tests. SQL/storage/authorizer здесь не выполнялись;
новое поведение query/resolve необходимо подтвердить при реализации на реальном consumer.

# Evidence — исполняемые probes

Probes фиксируют **текущее** поведение 0.3.x (`253cbf4`): зелёный probe = дефект воспроизведён. Они не входят в
продуктовый набор и лежат вне `tests/`, чтобы не ломать CI.

В новой версии совместимости с 0.3 нет, поэтому probes не «переворачиваются» на старом коде: каждый сценарий
переписывается на новый API с обратным ожиданием (дефект невозможен) в пункте плана из колонки «Probe»
[14-verification.md](../14-verification.md).

- [probes/OpusAuditProbesTest.php](probes/OpusAuditProbesTest.php) — core (Testbench, SQLite in-memory);
- [probes/OpusAuditContextProbesTest.php](probes/OpusAuditContextProbesTest.php) — с `azguard-context`;
- [probes-run.txt](probes-run.txt) — вывод прогона: **19 tests, 41 assertions, OK** (PHP 8.4.19, PHPUnit 12.5, Laravel 13 из lock).
- [baseline-suite.txt](baseline-suite.txt) — прогон существующего набора тестов на том же окружении.

Probes используют `DatabaseMigrations`, а не `RefreshDatabase`: без внешней транзакции кэш прав работает как в
production (см. P10b — почему это важно).

## Как повторить

```bash
# зависимости: codeload/api.github.com в этом окружении закрыты политикой egress, поэтому
# ставилось из git (--prefer-source) без rector/phpstan/larastan/type-coverage — на probes не влияет
composer install --prefer-source
cp audits/2026-09-29-audit/opus/evidence/probes/*.php tests/Feature/
vendor/bin/pest tests/Feature/OpusAuditProbesTest.php tests/Feature/OpusAuditContextProbesTest.php --testdox
rm tests/Feature/OpusAudit*ProbesTest.php
```

## Карта probes → находки

| Probe | Находка | Что доказывает |
|---|---|---|
| <a id="p01"></a>P01a | [N01](../01-review.md#n01-s1-wildcard-class-роли-действует-во-всех-панелях-документация-обещает-обратное) | class-роль с `*` панели `test` даёт `hasPermission`, `isSuperAdmin` и `Gate::allows` в панели `admin` |
| P01b | N01 | тот же `*` у DB-роли остаётся в своей панели — асимметрия |
| P01c | N01 | `hasPermission('admin.users.delete')` без панели оценивается по набору панели по умолчанию и разрешается её wildcard |
| <a id="p02"></a>P02 | [N02](../01-review.md#n02-s1-filament-позволяет-вписать-роли-произвольный-class_name-политики-делегирования-нет), [N12](../01-review.md#n12-s2-идентичность-роли--fqcn-записанный-в-данные) | роль с неразрешимым `class_name` (то, что пишет `EditRole`, или след переименования класса) → `InvalidRoleClassException` на каждой проверке держателя, включая Gate |
| <a id="p03"></a>P03 | [N03](../01-review.md#n03-s1-условно-gatebefore-игнорирует-настроенный-resolver) | resolver из `az-guard.resolver` отказывает, `hasPermission` = false, `Gate::allows` = true |
| <a id="p04"></a>P04a | [N04](../01-review.md#n04-s1-изоляция-запросов-hasscopedroles-не-изолирует) | без `Auth::user()` запрос scoped-модели не фильтруется |
| P04b | N04 | пользователь без scoped-строк видит все строки |
| P04c | N04 | две scoped-строки → AND → ноль строк |
| P05 | [N05](../01-review.md#n05-s2-мост-vaulter--azguard-отказывает-в-любом-реальном-хосте) | вызов в форме `vaulter-azgard` (неквалифицированный ключ, `panelId = null`) отказывает при существующем гранте |
| P06 | [N07](../01-review.md#n07-s2-merge-strategy-контекста--одна-на-все-панели) | `DenyWithoutContextStrategy` обнуляет права панели `admin`, где контекста нет |
| P06b | [N06](../01-review.md#n06-s2-контекстная-проверка-без-context-пакета-молча-становится-глобальной) | без context-пакета `hasPermission(..., $context)` = глобальный allow, `hasPermissionIn` = false |
| P07 | C01 (Codex), [D07](../02-decisions.md#d07) | **межзапросная утечка**: грант в контексте `(workspace, "a:7")` отдаётся из durable-кэша для `("workspace:a", 7)`; без кэша — отказ |
| P08 | [N08](../01-review.md#n08-s2-hasscopedpermission-живёт-по-своим-правилам) | DB-роль даёт право глобально, но не как scoped-назначение |
| P09 | [N09](../01-review.md#n09-s2-четыре-правила-выбора-панели-префикс-ключа-игнорируется) | при двух панелях `Gate::allows('admin.users.delete')` = false при действующем гранте; `hasPermission` без панели = false |
| P10 | [N17](../01-review.md#n17-s2-горячий-путь-дороже-чем-выглядит) | 10 проверок с тёплым request-кэшем = 10 `SELECT` ревизии |
| P10b | N17 | внутри любой транзакции источники перечитываются на каждой проверке |
| P11 | [N11](../01-review.md#n11-s2-события-неприменимы-как-интеграционный-контракт) | `HasDirectGrants::grant()` без события; повторный no-op grant — событие; событие внутри транзакции |
| P13 | [N14](../01-review.md#n14-s2-записи-без-валидации-и-молчаливые-no-op) | `assignRole('edtor')` — тишина; грант опечатки сохраняется и не действует |
| P14 | [N13](../01-review.md#n13-s2-superadmin--побочный-эффект-значения--в-любом-источнике) | `AzGuard::forUser()->on()->grant('*')` делает superadmin |

Не воспроизводилось исполняемо (только статически): эскалация через форму Filament целиком (N02 — показана
DoS-часть через ту же запись), чтение ревизии с реплики (C03 — нужен стенд primary/replica, сценарий V-серии в
[14-verification.md](../14-verification.md)), конкурентные гонки.

## Доработка целевого дизайна — 2026-09-30

[Design review](design-review.md) отделяет новые решения и ограниченную проверку модели от исторических
runtime probes выше. [design-model.py](design-model.py) — SQLite/unittest модель CRM;
[validate-dossier.py](validate-dossier.py) — целостность ссылок, нумерации и owning items целевых документов.
Эти проверки не означают реализацию или прохождение будущих V86–V107 в пакете.

[Сверка переименований](rename-consistency.md) фиксирует D71–D73 и согласованность builder API,
метаданных Resource, путей D72, config/CLI/stubs и verification scenarios.


## Настраиваемые контексты и реальные тесты CRM

- [flexibility-review.md](flexibility-review.md) — H15–H21, дополнительные Perplexity/primary checks, native guard collision.
- [guard-signature.php](guard-signature.php) — bounded native Eloquent signature probe; без БД, не новый runtime AzGuard.
- [17-crm-acceptance-tests.md](../17-crm-acceptance-tests.md) — 68 **future** реальных кейсов готовности пакета.
- [18-contexts-and-runtime-inputs.md](../18-contexts-and-runtime-inputs.md) — configured inputs/query/plugin contracts.

Запуск signature probe: `php audits/2026-09-29-audit/opus/evidence/guard-signature.php`.
Dossier validator включает D01–D83, V01–V120 (retired V40–V42), C01–C22, R01–R68 и F01–F24.


## Пересмотр ООП и authority

[oop-review.md](oop-review.md) документирует текущий проход и первичные OSS sources.
[19](../19-oop-and-permission-authority.md)/[20](../20-process-map.md) заменяют прежние DB role/profile/policy-OR-grants
гипотезы. [design-model.py](design-model.py) сохранён как историческая reference model предыдущей формулы; его
8 tests не квалифицируют текущую authority semantics и не пересчитывались для этого пересмотра.
Актуальная real runtime приёмка — R01–R68 / V01–V120 (retired V40–V42); все ещё future.

[oop-contracts.php](oop-contracts.php) — выполненный PHP probe typed factories/DTO/DI;
границы проверки и результат: [oop-review.md](oop-review.md#проверка-и-её-границы).

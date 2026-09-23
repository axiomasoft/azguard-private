# Проверка исходного аудита

Дата: 2026-09-22. Автор: GPT-6, текущая root-сессия. Это evidence основного дизайна;
оно не заявляет закрытие implementation defects.

## Baseline и достоверность

- P5 design preflight 2026-09-22: `plan-work.py design-context --phase P5` вернул
  `ROUTE_LAUNCH_MISMATCH`: provider-attested selector `gpt-6-sol` отсутствует в
  `swissknifeman/packages/task/configs/command-routing.json` (там frontier =
  `gpt-5.6-sol`). Это defect route mapping, а не GREEN Task check; текущий
  P5 design автор указан фактически как GPT-6. Source-owned исправление относится
  к swissknifeman и не выполняется в azguard plan scope.
- P7 design preflight 2026-09-23: `plan-work.py design-context --phase P7`
  повторил `ROUTE_LAUNCH_MISMATCH` с observed 0 mappings для `gpt-6-sol`.
  Проверка маршрута остаётся RED; P7 design опирается на прямые plan/code reads,
  а не на успешно полученный compact context.

- Audit SHA: `7e7009cc7c26e173cfa6bfec42365a311fc2adb0`; local HEAD:
  `67ebcde7b3ab32aa0205deb37a483b605e140cdb`. Оба объекта доступны локально.
- `git diff 7e7009c HEAD -- packages tests .github`: только три файла, 40 удалённых
  строк — base migration down(), auth-model guard в HasScopedRoles и его regression test.
  Не предполагаем порядок исправлений по дате текста: сравниваем фактические деревья.
- В начале были массовые удаления generated `.agents/skills/`, изменения AGENTS и
  других файлов настройки; каталог audits был untracked. Они не результат этого дизайна.
- Доступны PHP 8.4.1, Composer 2.8.3, vendor Laravel 13.21.1. Ограничение
  «нет PHP/Composer» относится к старому аудиту, не к этой сессии.
- MCP memory работает. Perplexity выполнил поиск. Web tool вернул
  `Fatal error: connection failed: error sending request`; использован прямой HTTPS
  fallback. Один неверный путь к Filament docs дал 404, затем открыт официальный
  `HasDatabaseTransactions.php` ветки 5.x и сверена installed implementation.
- P3 `finalize-design` первым запуском отклонил stale generated `bundles/P1.1.json`.
  Bundle пересобран owning `plan-views.py bundle --item P1.1`; повторный finalize P3
  завершён без warnings/baseline errors, но сам finalize снова изменил
  inputs bundle. Post-finalize rebuild вернул bundle в fresh состояние; до этого
  `design-context` не считался GREEN.
- В текущем AGENTS.md находятся umbrella swissknifeman sections (пять Python packages),
  тогда как этот репозиторий — PHP AzGuard. Эта смысловая ошибка проекции не является
  доказательством структуры AzGuard; исправление owning generator вне текущего scope.
- Точный model selector/effort root не предоставлен как аттестованный runtime carrier.
  Не заявляем, что основную работу выполнил sol. Будущая детализация явно sol/high.

## Карта findings

«Код» = прямой статический путь; «воспроизведено» = выполнена указанная проверка;
«уточнить» = runtime/support гипотеза, не доказанный exploit.

| ID | Вывод и привязка к исходному аудиту | Evidence на HEAD | Вывод для плана |
|:--|:--|:--|:--|
| F1 | **Новая находка: коллизия subject type в cache identity.** | EffectivePermissionResolver::forUser/forgetForUser передают только getAuthIdentifier(); PermissionCache::rememberForRequest/keyFor не включают morph type. HasScopedRoles::bootHasScopedRoles использует auth ID + entity class, без типа auth model. Cache-boundary reproduction ниже. | P1.1 первым: одинаковый ID разных subject types разделять в local/durable/scoped caches и invalidation. |
| F2 | AZG-001 «истечение grant и кэш» подтверждён; проблема шире persistent TTL. | DirectGrantSource::permissionsFor и ContextPermissionLayer::contextPermissions фильтруют expires_at только при SQL чтении; PermissionCache::loadFromStore сохраняет keys на configured TTL; requestCache hit не проверяет время вообще. | P1.2: абсолютный deadline на каждом hit, в том числе внутри одного request/job. |
| F3 | Предложение GrantContribution не учитывает весь public contract и wildcard. | PermissionSet @api является return type GrantSource и PermissionLayer; EffectivePermissionResolver::resolve немедленно возвращает global wildcard до layer. | P1.2: metadata должна переживать merge/filter/short-circuit; compatible seam, не прямая замена return type. Global superadmin context bypass уже явный contract. |
| F4 | AZG-002 «destructive Filament sync» подтверждён. | RolePermissionsRelationManager::syncPermissions: relation delete, затем RolePermission::insert, transaction отсутствует. RolePermission::booted validating saving hook на bulk insert не вызывается. Filament transactions default=false. | P2.1: service и validation/rollback tests на реальном action path; не полагаться на host panel config. |
| F5 | Новое уточнение Filament sync: форма не показывает весь persisted set. | currentPermissionsFormData строится из catalog groups зарегистрированных panels; syncPermissions удаляет ВСЕ dbPermissions. Wildcard/dynamic concrete/unknown-panel строки могут не попасть в форму. | P2.1: определить replacement scope; невидимые grants не удалять случайным сохранением формы. Runtime repro ещё требуется. |
| F6 | AZG-004 «fragmented invalidation» / backlog AZG-005: дубли есть, но число increment — не security invariant. | HasRoles flush + RoleAttached/Detached listeners; DirectGrant model events + GrantGiven/Revoked listeners. RolePermission имеет saving validation, но не authoritative saved/deleted invalidation. | P2.2: карта официальных mutations и behavior/no-op guarantees; не городить mutation ID только ради ровно одного bump. |
| F7 | AZG-005 «holder invalidation» / backlog AZG-006 подтверждён. | Filament sync перебирает Role::users(), привязанный к auth.providers.users.model и model_has_roles; другие subject types/scoped assignments не перечислены. | P2.2: охват role changes по revision/fencing либо эквивалентному протоколу; scoped-role и loaded relation caches отдельно. |
| F8 | Новые уточнения commit/backend consistency и shared-cache reset. | DirectGrant/ContextRole events вызывают flush немедленно; PermissionCache lock timeout может бросить после mutation. CacheResetCommand::handle вызывает flush всего configured store с предупреждением. | P2.2–P2.3: outer rollback, reader before commit, cache outage, generation resets; afterCommit не гарантирует atomic DB+cache. Reset blast radius документирован, не скрытый exploit; улучшить через namespace generation. |
| F9 | AZG-003 «config-swappable models» / backlog AZG-004 подтверждён частично по разным paths. | HasRoles::syncRoles использует Role::whereIn; HasScopedRoles — ModelHasScope::query/firstOrNew; GrantBuilder — DirectGrant::query; HasDirectGrants relation — default class. Некоторые relations/sources уже используют Config. | P3.1: целевая read/write matrix, не переписывать работающие пути. |
| F10 | Filament override gaps шире static model property. | RoleResource/DirectGrantResource модели, фильтры, RoleResource/Pages/CreateRole::handleRecordCreation и relation sync используют defaults. DatabaseRoleGrantSource использует DB table JOIN, без model scopes. | P3.2; Q4 разделяет поддержанную custom subclass/table семантику и ещё не обещанную custom connection/global scope. Blanket not->toUse(default model) запрещает корректные type-hints. |
| F11 | AZG-101 «mutable currentPanel» подтверждён; sync job safeguard уже есть. | AzGuardServiceProvider singleton manager; RequestReceived/JobProcessing resets. JobProcessing явно пропускает connectionName=sync; JobProcessingPanelResetTest зелёный. AuthorizationContextManager в context уже отдельный. | P4.1: scoped runtime с nested finally, сохранить существующую sync семантику; Laravel scoped не означает fiber-local. |
| F12 | AZG-102 «strict panel validation» подтверждён. | PanelResolver::resolveDefault проверяет explicit, но не configured default; resolve/resolveOrFail не выполняют registration validation; guardUnregistered возвращается при пустом registry. | P4.2: проверка итогового разрешённого ID; strict/legacy transition явный. |
| F13 | AZG-105 «middleware без attributes»: поведение подтверждено, автоматическая смена defaults не обоснована. | CheckAccess::handle и getPermissionAttributes возвращают пустой набор для missing/unsupported action; SkipGuardCheck уже существует и имеет test. | P4.2: разделить missing и explicit skip, добавить enforcement/diagnostics с BC contract. Новый SkipGuardCheck создавать не нужно. |
| F14 | AZG-106/107 permission normalization gaps; предложенное имя занято. | PermissionName::resolve уже есть; это resolver, не validated VO. CheckAccess::resolveAbility scope-ит только BackedEnum; public attribute допускает UnitEnum. PermissionKey::normalize сам не syntax validator. | P4.2: согласовать реальные enum/class-based/dynamic/wildcard forms; не вводить одноимённый VO и не сводить grammar к статическим трём сегментам. |
| F15 | Дополнительная разница с audit tree: auth-model global scope guard отсутствует. | Diff HasScopedRoles удаляет early return для auth.providers.*.model; boot scope начинает с Auth::check(), которое может снова загружать auth model. Соответствующий regression test также отсутствует. | P4.1: воспроизвести auth-loading recursion ограниченным тестом; пока статический риск, не заявленный воспроизведённый hang. |
| F16 | AZG-103 «role identity», AZG-104 invalid logic и AZG-108 construction подтверждены, решения аудита не единственные. | roles.name unique, class_name nullable/nonunique; SyncRolesCommand ищет по class_name, затем create по name; scaffold default role=Admin. Role::getRoleLogic возвращает null либо new class. | P5.1: две Admin definitions приводят прежде всего к unique-name failure, не доказанному тихому merge. Class FQCN тоже не refactor-safe без alias/migration. level часть getLevel/public display, удалять вслепую нельзя. |
| F17 | Scaffolding ограничения подтверждены. | MakeGuardPanelCommand lower(panel), isDirectory→failure без force, config regex; domain-policy.stub hardcoded User/domain model; command namespace и JSON уже частично развиты. | P5.2: повторяемое добавление domain и корректный model/actor; namespace cosmetics отложены. |
| F18 | Dedupe rebuild подтверждён, но выполняется только при duplicates. | 000005::dedupeModelHasRoles сначала hasDuplicates, затем distinct()->get(), delete(), chunked inserts; pivot без PK. | P6.1: bounded recovery-safe upgrade, не безусловное добавление surrogate id ради одного SQL примера. |
| F19 | Raw identifiers и null-aware uniqueness требуют точного engine/tuple анализа. | 000005 interpolates table name в raw SQL, выбирает mysql functional indexes; COALESCE scope_id сочетается со scope_type в composite index. | P6.2: scalar sentinel совпадение ещё не доказывает collision всей записи. Не запрещать all-zero UUID автоматически. MariaDB и schema-qualified names — отдельный support verdict. |
| F20 | Дополнительная разница с audit tree: base migration down() отсутствует. | 000000_create_az_guard_tables.php на HEAD без down(); audit commit содержит down(). | P6.1: проверить rollback contract и полный порядок миграций. На новых/старых установках разные upgrade исходники; неприемлем только новый forward fix, если fresh install раньше падает на старой 000005. |
| F21 | Context table configurability и diagnostics JSON уже существуют. | Context config table_names.context_roles, ContextPermissionLayer, миграция и ContextTableNameConfigTest; DoctorCommand signature --json. | Не создавать повторно backlog AZG-124/224. Сохранять текущий seam, покрывать конкретные gaps. |
| F22 | PostgreSQL/MySQL PR matrix уже существует; произвольная матрица аудита устарела относительно declared range. | .github/workflows/tests.yml: test-db-matrix=[pgsql,mysql], сервисы *_test; PHP 8.3/8.4/8.5 и Laravel 11/12/13; composer core ^11|^12|^13, Filament ^5. | P7.1: проверить installable combinations/minimal dependencies, а не «добавить DB CI». CI jobs не запускались в этой сессии. |
| F23 | Release order и loose action pins подтверждены; численные quality оценки не доказаны. | changelog.yml запускается push tag, затем пишет CHANGELOG в main; uses major tags. phpstan level=6; composer coverage min=50/type min=98. | P7.1–P7.2: changelog до tag, measured affected gates. Не объявлять 95%/85%, SBOM/signing обязательными без принятого контракта. |

## Уточнения решений аудита, не являющиеся дефектами

- Concept glossary RoleDefinition/RoleRecord/RoleAssignment полезен, но таблица/класс
  не переименовываются только из-за словаря. ContextRole действительно хранит permission
  без role_id; текущий термин context как workspace identity не запрещается ради
  искусственного правила «Context бывает только runtime».
- Mutable Panel, feature-first структура, восемь traits provider, новый schema registry,
  общий event envelope/exception interface — варианты архитектуры, без доказанной
  необходимости не блокируют correctness.
- Morph ID PHPDoc/context string(64) проверяются P6; string(64) для mixed integer/UUID —
  допустимый явный contract, не автоматическая ошибка.
- Mass-assignment guards dynamic class columns уже есть. Namespace allowlist не заменяет
  проверку role/scope interface, constructor behavior и authorization management UI.
- Global Gate before-hook требует regression, но наличие самого hook не доказывает bypass.
  Resolver комментирует намеренный global superadmin wildcard bypass context narrowing;
  смена этой семантики — отдельное публичное решение.
- Bulk Eloquent bypass documented; cache trigger/outbox не добавляются автоматически.
  P2 закрывает официальные write paths и описывает maintenance API для внешних bulk writes.
- При custom models конкретный базовый Role type-hint совместим с subclass. Архитектурный
  запрет на любой импорт Role из аудита слишком широк.
- Chatom claims здесь не верифицированы: соседний репозиторий не был входом design.
  Cross-project convergence и общий AGENTS исключены из implementation acceptance.
- Изменённый glossary/CLI naming не оправдывает переноса архивов в
  plans/active/completed: принятый Task layout уже другой.

## Неоднозначные исходные AZG-ID

Идентификаторы раздела «Agent-ready backlog» не совпадают с одноимёнными заголовками:

| Исходный ID | В разделе корректности | В backlog |
|:--|:--|:--|
| AZG-003 | Config-swappable models | RolePermissionValidator bypass |
| AZG-004 | Fragmented invalidation | Custom models |
| AZG-005 | Holder invalidation | Centralized invalidation |
| AZG-101 | Mutable panel | Role identity |
| AZG-102 | Strict panel validation | class_name identity |
| AZG-103 | Role identity | ContextRole naming |
| AZG-104 | Silent invalid role logic | ModelHasScope naming |
| AZG-105 | Missing attributes | Subject/scope vocabulary |
| AZG-106 | Permission grammar | Panel ID validation |
| AZG-107 | Pure/backed enums | PermissionName |
| AZG-108 | Direct construction | Pure-enum support |

Дальнейшая трассировка использует F# и полный заголовок исходного аудита.
Остальные backlog-группы охвачены так: persistence → P6 (AZG-124 уже реализован);
architecture → P1–P4 по поведению, naming/envelopes → roadmap;
DX → P5/P7 (JSON уже есть); quality → P7, произвольные thresholds → roadmap.

## Выполненные проверки

1. `pwd`, `git status`, оба commit objects, diff между audit SHA и HEAD,
   targeted source/test/CI reads. Прикладной код не изменялся.
2. Cache-boundary reproduction без БД и внешнего кэша: реальный PermissionCache,
   минимальный Illuminate container + config store=array. Первый callback для ID=1
   возвращает app.secret.read; второй для того же ID/panel должен вернуть empty.
   Фактический результат:

   ```json
   {
     "first_keys": ["app.secret.read"],
     "second_subject_expected_keys": [],
     "second_subject_actual_keys": ["app.secret.read"],
     "second_callback_calls": 0
   }
   ```

   Это воспроизведение cache boundary; потеря model type в forUser подтверждена
   чтением resolver. Полный HTTP/Eloquent adversarial regression ещё нужен в P1.1.
3. Targeted Pest на SQLite :memory:, APP_ENV=testing, cache/session=array, queue=sync:
   `PermissionCacheTest`, `ContextTableNameConfigTest`, `CheckAccessMiddlewareTest`,
   `JobProcessingPanelResetTest`, `ExtensionSwapTest`.
   **PASS: 24 tests, 39 assertions**, PAO duration 665 ms, exit=0.
   TestCase принудительно задаёт testbench connection и in-memory DB при sqlite;
   выбранные тесты не используют live DB/media disks.
4. Perplexity + прямое чтение официальных исходников, перечисленных в
   `docs/reference.md`: RAG:✅ scoped lifecycle, bulk model events,
   root transaction callbacks, Filament transactions default=false, Eloquent
   model table/connection/global-scope semantics и Filament `Resource::getModel()`.

Воспроизводимый PHP-код проверки пункта 2 (запуск из корня после
`require 'vendor/autoload.php'`; БД/файлы/сеть не используются):

```php
$app = new Illuminate\Container\Container;
Illuminate\Container\Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository([
    'az-guard' => ['cache' => ['store' => 'array']],
]));
$cache = new AzGuard\Registry\Resolver\PermissionCache;
$calls = 0;
$first = $cache->rememberForRequest(1, 'app', fn () =>
    AzGuard\Registry\Values\PermissionSet::fromKeys(['app.secret.read']));
$second = $cache->rememberForRequest(1, 'app', function () use (&$calls) {
    $calls++;
    return AzGuard\Registry\Values\PermissionSet::empty();
});
var_export([$first->keys(), $second->keys(), $calls]);
```

Не выполнялись: полная suite/PHPStan/Pint/Rector/mutation/coverage, real Redis/Octane,
PostgreSQL/MySQL/MariaDB migrations, полный Filament sync failure reproduction.
PASS существующих тестов не закрывает F1–F20 и не доказывает поддержку этих сред.

## 2026-09-23 — self-contained design recovery

- Сравнение с COREX platform-hardening показало representation gap: текущий
  план имел детальные item contracts, но не имел фазовых research/artifact
  carriers, которые execution model может прочитать без реконструкции
  authoring reasoning.
- Для P1/P2/P3/P4/P6/P7 выполнен focused Perplexity search; сырые ответы
  сохранены в `artifacts/Pn-design-rag/capture.md`. P5 не имел changing external
  premise; причина отказа от фиктивного search записана в P5 README.
- Primary-source web adapter в этой сессии вернул connection failure. Direct HTTPS
  подтвердил PostgreSQL 16 `NULLS NOT DISTINCT` и SQLite partial-index semantics;
  MySQL host не разрешился. Это не объявлено GREEN: граница и ссылки
  записаны в `research/P6-design-rag.md` и `docs/reference.md`.
- Perplexity synthesis для P6 ошибочно требовал исключить fallback sentinel из
  user domain. Нормативный dossier исправляет это: composite identity включает
  null-marker, поэтому `(1, fallback)` не сталкивается с `(0, real-value)`.
- Обнаружен defect Task adapter: документированный
  `task:plan-design <PLAN> finish` не проходил route capture, потому что CLI
  принимал только `--phase`. Исправление внесено в owning Task package;
  до его qualification эта проверка не считается GREEN.
- Повторный finish review выявил batch-route gap: compiler уже считал
  максимум class/effort/review, но item contract строился по route первого
  item. Task теперь передаёт compiled batch maximum в admission contract и отклоняет
  несовпадающий route. `roadmap.md` содержит exact `execution-sheet/v1`
  строку для каждого compiled admission; provider selectors из semantic Routing исключены.

## Design corrections к предлагаемым fixes

- Expiry проверяется на чтении обоих cache tiers. TTL rounding вверх, nonpositive TTL,
  clock boundary и wildcard shortcut должны быть в принятом дизайне; Redis TTL не
  заменяет absolute validUntil. Custom source без expiry metadata требует явной policy.
- Reader между flush и DB commit способен закэшировать старое состояние под новым epoch.
  После commit без повторной проверки оно останется. Это reasoning interleaving,
  не заявленный выполненный concurrent test.
- После-commit invalidation callback может не выполниться или упасть; DB commit уже
  состоялся. Ни transaction wrapper, ни retry/outbox сами по себе не обеспечивают
  немедленную revocation safety. Q2 требует fencing/revision/read policy.
- При role revision отдельно учитывать scopedRoleCache и уже загруженные Eloquent
  roles: сброс PermissionCache может снова наполнить его из stale relation.
- По умолчанию caching identity расширяется namespace/version без глобального flush.
  Реальная независимая DB identity/tenant connection не добавляется как обещание
  вслепую: Q4 определяет поддержанный contract.

---
id: D14
date: 2026-10-04
status: accepted
item: P4
items: [P4, P4.1, P4.2, P4.3, P4.4, P4.5, P4.6, P4.7, P4.8, P4.9, P4.10, P4.11, P4.12, P4.13, P4.14, P4.15, P5.1, P5.3, P6.1, P6.4, P6.6]
supersedes: []
superseded_by: null
---
# D14 — Состав и порядок P4, швы между пунктами, пробелы и расхождения досье по проверке прав

**Actor:** plan-designer / Claude Opus 5.5 (frontier)
**Evidence:** RAG:— `13-workstreams.md` F4, «Зависимости», «Уточнения пятого прохода», «Дополнение» D74–D83;
`09-authorization-semantics.md` §1–§16; `06-extension-points.md` §1, §2, §4, §7; `05-php-api.md` §4.1, §6, §7, §10;
`18-contexts-and-runtime-inputs.md` §2–§8; `19-oop-and-permission-authority.md` §2, §3, §5, §6; `20-process-map.md`
§2, §3 (F04–F07, F16, F23, F24); `16-crm-and-workflows.md` §6–§8; `17-crm-acceptance-tests.md` §1–§4;
`04-packages-and-layout.md` §2, §7; `03-glossary-and-renames.md` §13; `14-verification.md` строки V09–V33, V47, V49,
V53, V54, V66–V69, V74, V79–V82, V86, V88–V95, V99, V100, V102, V117–V120; решения досье — `brief/P4-dossier-decisions.md`.
Код HEAD `d02c904` (P3 закрыт GREEN): есть Kernel-значения решения (`AccessRequest`, `Decision`, `BeforeResult`,
`Grant`, `RoleContribution`, `PermissionAuthority`, `StateToken`, `CodeStateToken`, `DecisionSet`), `EvaluationContext`
без реализации, SPI источников и областей, `PanelCatalog` с `roles()`/`bindings()`/`bindingMethod()`/`withDynamic()`,
`RoleCompiler` (ключ, former keys, развёрнутые permissions, scopes, `super_admin`, `grantable`), `FolderSource`
(`ProvidesPermissions`/`ProvidesRoles`/`ProvidesPolicies`/`DescribesSchema`), `Storage`/`StorageRegistry`/`GrantFields`/
модели P3; движка проверки, `Restriction`, `GrantCondition`, `TenantPolicy`/`AssignmentScopePolicy`, `DatabaseSource`,
`RelationSource`, `GateSource`, кэша и Gate-адаптера нет; `SubjectResolver` — только интерфейс.

## Solution

1. **Состав и порядок.** Номера досье P4.1–P4.12 сохраняются. Новые пункты (D3: номер — следующий свободный):
   **P4.13** — срез-review, **P4.14** — Review P4, **P4.15** — CRM-фикстура и R-кейсы границы решения (выделена из P4.6:
   семантика 09 §3 и фикстура D5 вместе не помещаются в одну сессию исполнителя). Порядок исполнения: `P4.1` (solo) →
   `B4b` = P4.2–P4.3 → `B4c` = P4.4–P4.5 → `B4d` = P4.6–P4.7 → `P4.15` (solo) → `P4.13` (solo) → `B4e` = P4.8–P4.9 →
   `B4f` = P4.10–P4.11 → `P4.12` (solo) → `P4.14` (solo). Внутри batch порядок — по номерам; solo-пункты исполняются в
   порядке execution sheet; P4.12 после P4.4–P4.6 (13 «Зависимости»).
   Срез-review D3 («после пайплайна и источников») ставится после P4.7 и P4.15, а не после P4.5: контексты и
   суперадмин — часть той же границы решения (приёмка V88–V94 у P4.1–P4.7 в 13), а CRM-фикстура даёт срезу настоящий
   consumer. Находки среза исправляются в owning items P4.1–P4.7 до старта `B4e`, находки P4.14 — до закрытия фазы;
   механизм (repair-пункт по образцу D7 или owning repeat по образцу D11) выбирает владелец после verdict. Дефект,
   который показал CRM-кейс P4.15, исправляется в коде в рамках P4.15 с записью owning item (D5: не маскировать deny).

2. **Точка входа движка — внутренний `Authorization\Authorizer`** (04 §2): `decide`, `decideMany` (P4.9), `explain`
   (P4.10), `isSuperAdmin` (P4.7), `visibleTo` (P4.12), `touch` (P4.8). Публичные поверхности над ним — трейт и
   `SubjectAccess` (P5.1), фасад и `PanelAccess` (P6.1), middleware (P6.2). Приёмка досье, сформулированная «через
   трейт/фасад», в P4 проверяется на `Authorizer` и Gate (P4.11); остальные поверхности добавляют свои владельцы и обязаны
   идти через `Authorizer` (arch-правило P4.1). Субъект-модель по умолчанию находит внутренний
   `Authorization\ModelSubjectResolver` (реализация `SubjectResolver` по `Panel::subjectModels()`), владелец — P4.1.

3. **Швы между пунктами.** Каждая общая часть имеет одного владельца, временных типов и заглушек нет (принцип D6/D8):
   - **Вызов политики** — `Policies\PolicyDecider` (04 §2) создаёт **P4.1**: привязка каталога → метод с `#[Decides]` →
     вызов через контейнер с моделью субъекта и ресурсом → `true`/`null`/`false`/`Response`; исключение → `PolicyError`.
     Это нужно диспетчеру и V53. **P4.3** расширяет: class-аргумент для `viewAny`/`create`, нативный `before` политики,
     сохранение `Response` в решении, `GateSource`, индекс «модель + слово ability → право» и матрицу V67/V68/V80/V93.
   - **Детали отказа.** `Decision` получает nullable `message`, `status`, `code` (из `Response` политики) — **P4.3**;
     `Decision::toGateResult()` — **P4.11** (D6 п.1). Kernel не импортирует Laravel: поля — скаляры.
   - **Трасса.** Внутренний `Authorization\Pipeline\Trace` (no-op без `AccessRequest::traced()`) вводит **P4.1**, стадии
     пишут в него; `Kernel\Decision\Explanation`, редактирование секретов и `azguard:explain` — **P4.10**.
   - **Граница области по умолчанию.** До P4.6 у каждой панели фактически `TenantPolicy::none()` и
     `AssignmentScopePolicy::none()`; P4.1 реализует ровно эту строку: явный не-global tenant → `Deny(TenantMismatch)`,
     явный не-global context → `Deny(AssignmentScopeNotAccepted)`. P4.6 вводит классы политик и всю таблицу 09 §3.
   - **Проверенное чтение DB authority (fence).** 20 §2 «Read state: source-specific fence». В досье нет имени контракта,
     через который источник отдаёт свой токен состояния, а встроенные источники пишутся только на публичных контрактах.
     Вводится `@spi` `Contracts\Sources\FencesReads` с одним методом `state(Panel $panel, TenantRef $tenant): StateToken`
     (свежее чтение с primary). Цикл `T_before` → все capability-чтения этого источника и dynamic overlay → `T_after`,
     до 3 попыток, затем `Deny(ConsistencyError)`, реализует **P4.4** как первый fenced источник (уточнение D12 п.8,
     где протокол целиком отдан P4.8/P4.9). **P4.8** добавляет поверх него кэш (память запроса и store), режимы
     `state_refresh`, сроки, incarnation/restore и доказательство V99 двумя процессами с барьерами; **P4.9** — один fence
     на все пачки группы. Решение без fenced источника несёт `CodeStateToken`.
   - **`FiltersQueries` и `AssignmentScopeSelection`** (`@spi`, `Contracts\Sources\`) вводит **P4.4** (первый
     реализатор), реализует ещё **P4.5**, потребляет **P4.12**. `FiltersAccessQueries` (`@spi`,
     `Contracts\Authorization\`) и `AccessPredicate` (чистое значение `Kernel\Decision\AccessPredicate`, 04 §7 без
     папки — рядом с решением, без новой подпапки Kernel) — **P4.12**.
   - **`touch()` панели** — внутренняя операция `Authorizer::touch()` через `Storage::mutate` в **P4.8** (часть V66 о
     сбросе кэша переходит туда); публичный `PanelAccess::touch()` — P6.1.
   - **CRM-фикстура (D5)** создаётся в **P4.15** сразу после P4.7: tenant A/B, `ProjectScope`, `TenantPolicy::required`,
     членство и суперадмин к этому моменту выразимы. Таблицы хоста — миграции фикстуры, данные — сидер без повторения
     алгоритма решения. Кейсы R пишутся на поверхности `Authorizer` с id кейса в имени теста; поверхности
     трейта/HTTP/UI добавляют P5–P7. Распределение: P4.15 — R01, R02, R06, R09–R14, R16, R17, R20–R22, R44, R61, R62,
     R65 (чтение); P4.8 — R51, R52; P4.9 — R68; P4.12 — R31–R33, R35, R64 (список).
   - **Хранилище публично.** `Storage` получает `@api` (D12 п.4 отдал решение P4.4) с поверхностью `id()`,
     `connectionName()`, `prefix()`, `hostKeys()`, `state()` и `static own(string $connection, string $prefix = 'azg_',
     ?string $hostKeys = null): self`; `mutate()`, `table()`, `connection()`, `model()`, `schema()` помечаются
     `@internal`. `own()` возвращает уже зарегистрированное хранилище той же пары `(разрешённое подключение, префикс)`
     при совпадающих host keys, иначе регистрирует новое с id `own-<подключение>` (недопустимый id — check `storage`);
     расхождение host keys у той же пары — `InvalidConfigurationException` check `storage`.

4. **Расхождения внутри досье решены по позднему нормативному слою:**
   - **FormerKeys не runtime-alias.** Строка V10 («выдачи по старому ключу действуют») противоречит 19 §6, D80 и 20 F16
     («FormerKeys — metadata для явной transactional migration, не runtime fuzzy alias»; «removed key zero authority»).
     Принимается поздний слой: выдача с прежним ключом даёт ноль прав и диагностику, называющую текущую роль; доступ
     возвращает явная миграция `roles:rename-key` (P5.3/P6.6). P4.2 проверяет именно это; остаток V10 — P5.3.
   - **Выдача роли с `#[NotGrantable]` из хранилища** (строка, обошедшая API) даёт ноль прав и диагностику, как
     неизвестная роль: такая роль назначается только правилом (D14 досье). `RoleNotGrantableException` при попытке выдачи
     — P5.2/P5.3.
   - **Проверка внутри транзакции хоста.** 09 §8 требует configuration error, если в транзакции приложения со старым
     snapshot нельзя обеспечить fresh authority, а 09 §14 описывает поддержанный протокол защищаемой записи — проверку
     внутри общей транзакции хоста после блокировки `panel_state`. Отличить эти случаи по подключению нельзя, а
     безусловная ошибка ломала бы каждую проверку внутри `DB::transaction` и тесты с транзакционным откатом. Принято
     (P4.8): внутри чужой транзакции на подключении хранилища кэш не используется и не публикуется, чтение идёт через эту
     транзакцию, `StateToken` решения — токен её snapshot; гарантия отзыва 09 §8 относится к проверкам вне такой
     транзакции, что фиксирует документация P8.2. Владелец может ужесточить это до ошибки отдельным решением.
   - **Суперадмин:** authority-кандидат в Grants mode — часть пайплайна **P4.1** (F4: «scoped superadmin»); **P4.7** —
     `isSuperAdmin(on:)`, `TenantPolicy::allowGlobalRoles()`, `exemptsSuperAdmin()` и матрица V17/09 §4.

5. **Новые публичные имена P4.** Из досье: `Contracts\Authorization\{Restriction,GrantCondition,FiltersAccessQueries}`,
   `Contracts\Sources\{FiltersQueries}`, `Kernel\Decision\{Explanation,AccessPredicate}`, `Scopes\{TenantPolicy,
   AssignmentScopePolicy,ContextAware,ModelAssignmentScopeDefinition,ModelTenantDefinition}`,
   `Sources\Database\DatabaseSource`, `Sources\Relation\RelationSource`, `Sources\Gate\GateSource`,
   `Authorization\Visibility`, `Laravel\Gate\GateBridge`, исключения `VisibilityNotSupportedException` (P4.12) и
   `RecursionDetectedException` (P4.1). В проверке tenant/scope-нарушения — причины отказа `DecisionReason`, не
   исключения; `TenantRequiredException`, `TenantMismatchException`, `AssignmentScopeRequiredException`,
   `ResourceScopeMissingException`, `AssignmentScopeNotAcceptedException` (05 §10 — `ChangeException`) вводит пайплайн
   изменений P5.2. Вне досье — только
   `Contracts\Sources\FencesReads` и `Contracts\Sources\AssignmentScopeSelection` (имя есть в 06 §1.1, форма — нет:
   `everywhere()`, `in(list<AssignmentScopeRef>)`, `nowhere()`). Внутренние классы без тега пункты называют в `Files`.

6. **Среды СУБД (уточнение D4).** P4.4 гоняет группу `engines` (чтение назначений, fence, канон id) на PG 16, MySQL 8,
   MariaDB 10.11; P4.8 — V99 двумя процессами с барьерами на PG/MySQL и cache store Redis 7 (`--group=redis`); P4.12 —
   EXPLAIN V33 на PG/MySQL. Недоступная СУБД или Redis — `unavailable`, пункт не закрывается 🟢.

7. **Приёмка, которую P4 закрывает частично** (остаток — владельцам кода): V10 — см. п.4, миграция P5.3; V11 «doctor
   показывает» — диагностика в логе/трассе в P4.2, doctor-проверка P6.4; V30 — Gate/`@can`/`decideMany`/`explain` в
   P4.11, трейт и фасад P5.1/P6.1; V66 — `RoleNotGrantableException` P5.2/P5.3; V82 — создание/удаление динамических
   прав P5.3, чтение и overlay P4.4; V91/V92 — чтение в P4.4, cleanup/delete P5.3; V95 — UI-поверхности P7.2;
   V102 — `PolicyBinding`/`Decides` runtime P4.3, Filament P7.2; V120 — mixed batch P4.9, process-map gates P5.2/P6.8/P8.7;
   V54 — условие видит поля своей выдачи P4.1, выборка только объявленных decision-полей P4.4, отсутствие необъявленных
   полей в кэше P4.8. Свойства V15: P1–P3, P5–P8, P10–P12, P15 — P4.1; P4, P13 — P4.6; P9 — P4.11; P14 — P4.12; P16 — P4.8.
   13 F4 называет приёмкой P4.10 строку V27, но строка V27 в 14 описывает Gate (владелец P4.11): P4.10 принимается по
   09 §11/D29 и части V30 (`explain`). `Decision::toGateResult()` D6 п.1 исполняется как `GateBridge::toGateResult()`:
   D6 сам требует, чтобы Kernel не импортировал Laravel `Response`.
   Probe P03 (V30: одинаковый запрет через Gate, `decideMany`, `explain`) переходит из P4.3 в **P4.11**: раньше этих
   поверхностей нет; P4.11 меняет `Owning item` в `tests/Regression/specs/P03.md` и пишет `Owning rationale`.

## Why

F4 смешивает части с разными первыми потребителями: вызов политики нужен диспетчеру раньше, чем режимы политик целиком;
fence нужен первому DB-источнику раньше, чем кэшу; Explanation опирается на трассу пайплайна. Без явного владельца каждого шва
исполнитель либо пишет временный тип, либо дублирует семантику. Поздний нормативный слой досье (19, 20, D80)
формулирует FormerKeys и removed keys строже строки V10; выбор слабого варианта сделал бы ключ роли неявным alias.
Срез-review после P4.7 проверяет границу решения целиком до кэша, который иначе закрепил бы ошибку в ключах.

## Consequences

Phase Context P4 и спецификации P4.1–P4.14 следуют этому решению. P5.1/P6.1/P6.2 строят поверхности над `Authorizer`;
P5.3 владеет миграцией FormerKeys и `RoleNotGrantableException`; P6.4 — doctor-проверками removed roles/relations/
decision-полей; P6.6 — reset/restore со сменой incarnation (P4.8 доказывает только поведение кэша при новой incarnation).
Routing: `B4` из двенадцати пунктов заменяется на `solo` P4.1, `B4b`, `B4c`, `B4d`, `solo` P4.15, `solo` P4.13, `B4e`,
`B4f`, `solo` P4.12, `solo` P4.14.

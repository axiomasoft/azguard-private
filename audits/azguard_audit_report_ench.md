Вердикт: **архитектура AzGuard выглядит зрелой, но текущий HEAD я не рекомендую объявлять production-ready или выпускать как 1.0 без дополнительного hardening-релиза**. Прямого подтверждённого обхода авторизации в актуальном коде я не обнаружил, однако остаются release-blockers в цепочке поставки, достоверности релизной квалификации, кэше полномочий, документации совместимости и эксплуатационных границах.

## Границы аудита

Аудит выполнен по HEAD `93258463fa077d7dc7a262b41b5c6bd3eb6ea23b`: production-код, миграции, тесты, CI/CD, release/split-процесс, документация, предыдущие внутренние аудиты и зафиксированные evidence-артефакты.

Это статический аудит с проверкой репозиторных доказательств. Полный набор тестов на этом HEAD независимо не перезапускался: в checkout нет `vendor/` и `composer.lock`, поэтому воспроизводимость текущих результатов отдельно не подтверждена.

## Итоговая оценка

| Область | Оценка | Статус |
|---|---:|---|
| Архитектура авторизации | 9/10 | Сильная |
| Fail-closed поведение | 9/10 | Сильное |
| Консистентность БД | 8.5/10 | Хорошая, есть эксплуатационные оговорки |
| Тестовая база | 9/10 | Очень широкая |
| Расширяемость | 9/10 | Высокая, но сложная |
| Безопасность supply chain | 5/10 | Требует исправления |
| Воспроизводимость релиза | 5/10 | Не готово |
| Операционная безопасность | 7/10 | Требует обязательного runbook |
| Документация совместимости | 5/10 | Есть противоречия |
| Общая готовность | 6.8/10 | **NO-GO для 1.0 сейчас** |

## Блокирующие проблемы

| ID | Приоритет | Проблема | Риск |
|---|---|---|---|
| REL-01 | Blocker | Нет финальной квалификации актуального HEAD | Релизные evidence относятся к более ранним кандидатам |
| REL-02 | Blocker | Laravel 11 заявлен в README, но исключён из Composer и CI | Пользователи получают ошибку установки или ложное обещание поддержки |
| SEC-01 | High | Кэш полномочий не имеет контроля целостности | Компрометация или совместное использование Redis позволяет подменять authority-объекты |
| SEC-02 | High | GitHub Actions используют mutable tags вместо commit SHA | Supply-chain compromise может получить release token или split PAT |
| SEC-03 | High | CodeQL, Zizmor, mutation и dependency audit не блокируют release graph | Релиз возможен без обязательного security quality gate |
| REL-03 | High | Нет `composer.lock` для monorepo CI | Разные CI jobs могут проверять разные dependency graphs |
| SEC-04 | High | SECURITY.md ведёт на другой GitHub owner/repository | Уязвимость может быть отправлена не туда или остаться незарегистрированной |
| OPS-01 | High | Актуальные релизные доказательства частично self-reported | Нужен независимый, immutable evidence bundle на одном SHA |
| AUTH-01 | High | Change API не является самостоятельной границей авторизации | Ошибка интеграции контроллера может позволить выдать роль без предварительного permission check |
| OPS-02 | Medium | Ошибки authority по умолчанию превращаются в 403 | Инциденты БД, source или policy могут выглядеть как обычные отказы пользователя |

### Финальная квалификация

Внутренний `RESOLUTION.md` прямо оставляет R16 — итоговую квалификацию — незавершённой. При этом зафиксированный 1.0 gate относится к более старым commit-кандидатам, после которых исправлялись runtime-контракты, Filament и compatibility baseline.

Публично видимы только теги `v0.7.0` и `v0.3.0`; тега `v1.0.0` нет . Следовательно, сведения в планах о том, что `v1.0.0` «идёт на candidate commit», пока нельзя считать состоявшимся релизом.

### Совместимость Laravel

README заявляет Laravel 11–13, тогда как [`packages/core/composer.json`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/packages/core/composer.json) допускает только Illuminate 12 и 13. [CI matrix](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/.github/workflows/tests.yml#L20-L38) также проверяет только Laravel 12 и 13. [github](https://github.com/axiomasoft/azguard-private)

Перед релизом необходимо синхронно обновить:

- README;
- installation guide;
- requirements и compatibility page;
- upgrade guide;
- Packagist metadata;
- consumer fixtures;
- release notes.

## Кэш полномочий

[`PermissionSetCache`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/packages/core/src/Authorization/Internal/Cache/PermissionSetCache.php#L109-L150) сохраняет сериализованные `Grant` и `RoleContribution` в Laravel cache store. `allowed_classes` снижает риск классической PHP object injection, но **не доказывает подлинность допустимых объектов**.

Если другой сервис, tenant или атакующий может писать в тот же Redis/Memcached namespace, он потенциально сможет:

- создать поддельный grant;
- создать role contribution;
- изменить scope или expiry;
- вызвать устойчивое повышение привилегий до смены state key или TTL.

Это не удалённая уязвимость при правильно изолированном Redis, но это критичная trust boundary.

### Обязательные меры

- Использовать отдельный cache store и отдельные Redis credentials для AzGuard.
- Запретить общий Redis namespace между приложениями и окружениями.
- Включить TLS и ACL, запретить публичную доступность Redis.
- Добавить подписанный cache envelope: version, payload, HMAC.
- При невалидной подписи удалять entry и перечитывать source.
- Не разрешать бесконечный TTL в production.
- Добавить `azguard:doctor` check на `cache.ttl=null`, array/file store и общий префикс.
- Добавить тесты cache poisoning, cross-app collision и key replay.

Есть дополнительный риск роста: при `cacheTtl() === null` entry получает срок до 9999 года, а revision-keyed старые ключи не удаляются. При частых изменениях ролей это может дать неограниченное накопление устаревших записей.

## Цепочка поставки

### Непривязанные Actions

Workflows используют версии вроде:

- `actions/checkout@v6`;
- `actions/cache@v4`;
- `shivammathur/setup-php@v2`;
- `softprops/action-gh-release@v3`;
- `dependabot/fetch-metadata@v3`;
- `symplify/monorepo-split-github-action@v2.4.5`.

Особенно опасен [`split.yml`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/.github/workflows/split.yml#L42-L55): сторонний action получает `MONOREPO_SPLIT_TOKEN` с правом записи в публичные package repositories.

Нужно:

- закрепить каждый action на полный 40-символьный commit SHA;
- оставить version-комментарий рядом с SHA;
- включить Dependabot для обновления pinned SHA;
- ограничить split token только двумя репозиториями;
- использовать GitHub App или fine-grained token вместо классического PAT;
- сделать environments с manual approval для production release.

### Security gates

[`release.yml`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/.github/workflows/release.yml#L17-L125) требует tests, static analysis, style, docs, nightly и compatibility, но не требует:

- CodeQL;
- Zizmor;
- mutation testing;
- `composer audit`;
- secret scanning;
- SBOM;
- artifact provenance.

Mutation workflow помечен как ручной и необязательный в [`mutation.yml`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/.github/workflows/mutation.yml#L1-L15). Для authorization framework это недостаточно: mutation testing должен быть обязательным хотя бы для authorization pipeline, visibility compiler, change pipeline и Filament adapters.

### Воспроизводимость Composer

В monorepo отсутствует `composer.lock`, хотя static-analysis, code-style, compatibility и mutation jobs выполняют `composer install`. Без lock-файла фактически разрешение зависимостей может меняться между jobs и запусками.

Рекомендуется:

- Закоммитить root `composer.lock` как lock development/CI environment.
- Оставить отдельные matrix jobs с намеренным `composer update --prefer-lowest/prefer-stable`.
- Сохранять `composer.lock`, `composer show --locked` и PHP extensions в release evidence.
- Добавить `composer audit --locked --abandoned=fail`.
- Генерировать CycloneDX/SPDX SBOM для каждого package archive.

## Security policy

[`SECURITY.md`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/SECURITY.md#L7-L18) ведёт на `github.com/axioma-studio/azguard/security/advisories/new`, тогда как репозиторий находится у `axiomasoft/azguard-private`. Это необходимо исправить до публикации.

Также стоит добавить:

- точную таблицу поддерживаемых веток;
- срок первичного ответа;
- целевые сроки triage и исправления по severity;
- правила coordinated disclosure;
- security contact в `composer.json`;
- `security.txt`;
- процесс выдачи CVE/GHSA;
- policy для утечек signing/release tokens.

## Авторизационная модель

### Сильные стороны

Архитектурно пакет построен хорошо:

- Один decision pipeline используется для scalar checks, Laravel Gate, middleware, attributes, Filament и batch.
- Source, policy, condition и restriction errors дают отказ, а не разрешение.
- Вклад неизвестной или негрантируемой роли отвергается.
- Former role keys не принимаются молча и требуют миграции.
- Tenant и assignment scope проверяются до квалификации вклада.
- Super-admin не обходит ошибку source.
- Visibility отказывается работать, если точную SQL-семантику построить невозможно.
- Authority writes блокируют `panel_state`, обновляют revisions и публикуют события только после commit.
- Mutable request state зарегистрирован как Laravel scoped, что правильно для Octane и queue workers.

Не обнаружено очевидного SQL injection через storage prefix: конфигурация ограничивает prefix регулярным выражением, а динамические имена таблиц и constraints проходят через query grammar wrapping.

### Change API

Методы `grantRole()`, grant managers и mutation pipeline являются программным API изменения полномочий. Они обеспечивают консистентность, аудит и транзакцию, но не могут сами определить, имеет ли текущий HTTP-пользователь право выдать роль.

Это должно быть явно сформулировано как security boundary:

> Перед каждым вызовом change API приложение обязано отдельно авторизовать actor и допустимый target role/scope.

Нужно добавить reference service:

```php
$assignment = $roleAssignments->authorizeFor(
    actor: $currentUser,
    subject: $targetUser,
    role: EditorRole::class,
    scope: $team,
);

$assignment->grant();
```

Либо предоставить opt-in `RoleAssignmentAuthorizer`/policy contract, чтобы интеграторы не писали эту чувствительную логику каждый раз.

### Actor identity

`AzGuard::actingAs(string)` позволяет application code записать произвольного system actor reason. Это нормально для доверенного backend-кода, но audit log не является криптографическим доказательством личности.

Нужно документировать:

- не передавать string reason напрямую из HTTP input;
- использовать authenticated model для пользовательских операций;
- system actor разрешать только console/jobs;
- при строгом аудите записывать request ID, IP, auth guard и immutable actor ID.

## Visibility

### Точное fail-closed поведение

Хорошее решение: произвольные automatic roles не пропускаются молча. Если source не способен представить их через query selection, [`visibleTo()` выбрасывает исключение](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/tests/Feature/Visibility/UnsupportedTest.php#L135-L152).

Аналогично row-dependent `matchingGrants()` не подменяется пустым массивом. Это безопаснее, чем возвращать слишком широкую выборку.

### Ограничения

`visibleTo()` компилирует запрос по grants, прочитанным в момент построения Builder. Если grant отозван до фактического выполнения SQL, строка может быть показана один раз. Это прямо зафиксировано в [`consistency.md`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/docs/advanced/consistency.md#L125-L129).

Обязательное правило:

- `visibleTo()` подходит для списков и read UI;
- перед update/delete/export/download нужно повторно вызвать scalar `authorize()` на конкретной записи;
- bulk actions должны повторно авторизовать каждую запись либо применять атомарный authority predicate внутри write query.

Нужны дополнительные тесты:

- revoke между query construction и execution;
- bulk action над изменившимся набором;
- export/download;
- cursor pagination после смены grants;
- soft-deleted и global-scoped models;
- relation managers и morph relations;
- 10 000+ contribution branches;
- property-based parity: scalar result равен membership в `visibleTo()`.

## Consistency и реплики

При `Reads::Primary` дизайн сильный: repeatable-read snapshot, subject revisions, epoch/incarnation и bounded fencing. Но state token покрывает authority source, а не произвольные host membership tables или данные, прочитанные policy/condition.

Риски custom extensions:

- Source неправильно объявил `Volatility::Stable`.
- `FencesReads::state()` не меняется при изменении grants.
- Source зависит от host membership, но state token этого не отражает.
- Cache TTL превышает допустимый revoke latency.
- Query filter и scalar source реализуют разную семантику.

Для каждого source contract suite должен проверять:

1. Grant и revoke меняют token.
2. Неизменившееся состояние не меняет token.
3. Scalar, batch и visibility дают эквивалентный результат.
4. Tenant и context не пересекаются.
5. Истёкший grant никогда не возвращается из кэша.
6. Ошибка/timeout дают failure, а не частичный результат.
7. Source не читает данные другого panel.
8. Fencing retry ограничен и идемпотентен.

Replica mode по определению допускает stale grant после revoke. Для административных и destructive операций следует принудительно использовать primary независимо от panel default.

## Ошибки и наблюдаемость

Core различает outcome, transient failure и contract failure, но Laravel Gate и обычный middleware по умолчанию отвечают 403. [`DecisionResponder`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/packages/core/src/Laravel/Http/DecisionResponder.php#L13-L53) является opt-in.

В [`FilamentGate`](https://github.com/axiomasoft/azguard-private/blob/93258463fa077d7dc7a262b41b5c6bd3eb6ea23b/packages/filament/src/Authorization/FilamentGate.php#L185-L218) любой `Throwable` логируется и превращается в 403. Это fail-closed, но может скрыть production outage.

Нужно:

- Ввести отдельные counters для `source_error`, `consistency_error`, `policy_error`, `hook_error`.
- Логировать correlation ID, panel, source и stable code.
- Не включать subject IDs и grant fields по умолчанию.
- Настроить alert на любой contract failure.
- Для API рекомендовать 503 на transient authority failure.
- Добавить OpenTelemetry events/metrics.
- Сделать Filament notification generic, но сохранить 5xx в server response для contract failures, если это не ломает UX.

## Гибкость пакета

### Хорошие паттерны

- Pipeline/chain of responsibility для решения доступа.
- Ports and adapters для sources, policies, restrictions и query filters.
- Immutable value objects для identity и state.
- Transactional outbox-like публикация после commit.
- Revision-keyed cache вместо decision cache.
- Contract test kits для extension authors.
- Явные authority modes: grant-required, policy-only, granted-to-all.
- Separate core и Filament package.
- API manifest и compatibility baseline.
- Fail-closed unsupported вместо приблизительного SQL.

### Цена гибкости

Гибкость создаёт много семантических комбинаций:

- panel;
- tenant;
- context inheritance;
- automatic и stored roles;
- direct grants;
- wildcard patterns;
- policies;
- before hooks;
- restrictions;
- conditions;
- external sources;
- query filters;
- replica modes;
- caching/fencing.

Для production-готовности нужен machine-readable capability matrix: какие combinations поддерживают scalar, batch, visibility, cache и Filament. Сейчас часть ограничений обнаруживается только runtime exception.

Рекомендуется ввести:

```php
$capabilities = $source->capabilities();

$capabilities->supportsScalar();
$capabilities->supportsBatch();
$capabilities->supportsVisibility();
$capabilities->supportsStableCache();
$capabilities->supportsTenantInheritance();
```

Компилятор panel должен отклонять несовместимую конфигурацию на boot/doctor, а не ждать первого запроса.

## Производительность

Положительные стороны:

- Subject revision не инвалидирует кэш остальных субъектов.
- Batch использует один snapshot на connection.
- IN-list ограничен.
- Request-local memo сбрасывается как scoped service.
- Automatic folder roles намеренно обходят cache, чтобы читать live host data.

Последний пункт может стать performance cliff: наличие хотя бы одной automatic role в каталоге отключает cache для соответствующего FolderSource. Нужно измерять отдельно:

- 1, 10, 100 automatic roles;
- 100–500 permissions на роль;
- 1–10 тысяч visible branches;
- cold/warm Redis;
- policy-heavy checks;
- Filament tables с count/pagination;
- Octane worker после 10–100 тысяч запросов.

Также нужен memory benchmark shared cache при частых grant/revoke и nullable TTL.

## Тесты

Репозиторий содержит очень широкое покрытие: unit, feature, architecture, contracts, regression, engine-specific, Redis, replica, compatibility, package consumer и acceptance tests. Внутренний audit resolution сообщает о примерно 4 973 зелёных SQLite tests после исключения Laravel 11, но это self-reported evidence, которое необходимо повторить на финальном SHA.

Перед production нужны дополнительные обязательные классы тестов:

- Mutation testing как blocking gate.
- Cache poisoning и corrupted serialization.
- Chaos: Redis unavailable, stale replica, DB disconnect, deadlock, timeout.
- Fuzzing identity codec, permission grammar и wildcard matching.
- Property-based scalar/batch/visibility parity.
- Forged Livewire requests и hidden-action invocation.
- Cross-tenant model substitution.
- Authorization during rollback and nested host transaction.
- Worker memory/leak test под реальным Octane.
- Upgrade/downgrade from every supported minor.
- Backup restore с обязательным state reset.
- Installation from built archives на каждом PHP/Laravel pair.
- Minimum dependency versions отдельно от latest stable.
- Reproduction from committed lock and isolated Composer cache.

## План исправлений

### До первого production

1. Зафиксировать один release SHA и запретить изменения после начала квалификации.
2. Исправить Laravel requirements во всех документах.
3. Исправить ссылку private vulnerability reporting.
4. Закоммитить root `composer.lock`.
5. Pin всех Actions на commit SHA.
6. Сделать CodeQL, Zizmor, mutation и `composer audit` обязательными для release.
7. Подписать или изолировать permission-set cache.
8. Запретить nullable cache TTL в production либо добавить bounded eviction.
9. Добавить explicit threat model и trust boundaries.
10. Добавить security checks в `azguard:doctor --production`.
11. Перезапустить весь matrix на одном commit.
12. Собрать packages, установить их в чистые приложения и проверить checksum.
13. Создать SBOM и provenance attestation.
14. После успешных gates поставить подписанный `v1.0.0` tag.
15. Проверить split repositories и Packagist до публикации GitHub Release.

### После релиза

- Capability declaration для custom sources.
- OpenTelemetry integration.
- Более строгий actor provenance.
- Atomic bulk authorization helpers.
- Signed cache envelope.
- Performance regression thresholds.
- LTS/security branch policy.
- Automated upgrade matrix с предыдущего minor release.

## Production gate

Релиз можно считать готовым только если выполняются все пункты:

- [ ] README и Composer заявляют одинаковую compatibility matrix.
- [ ] SECURITY advisory URL работает.
- [ ] Финальный SHA неизменяем.
- [ ] Все Actions закреплены на SHA.
- [ ] `composer.lock` присутствует.
- [ ] `composer audit` не находит блокирующих advisory.
- [ ] CodeQL и Zizmor блокируют релиз.
- [ ] Mutation thresholds выполнены.
- [ ] SQLite, PostgreSQL, MySQL, MariaDB и Redis lanes зелёные.
- [ ] PHP 8.3–8.5 и Laravel 12–13 проверены.
- [ ] Built archives установлены в чистые приложения.
- [ ] Cache store изолирован и имеет конечный TTL.
- [ ] Replica mode запрещён для destructive/admin flows.
- [ ] `visibleTo()` не используется как единственная защита write operations.
- [ ] Error-denial metrics и alerts настроены.
- [ ] Backup/restore runbook включает `azguard:state:reset`.
- [ ] SBOM, checksums и provenance опубликованы.
- [ ] Только после этого создаётся `v1.0.0`.

**Финальное решение: NO-GO сейчас.** Кодовое ядро близко к production-grade, но release engineering и security boundaries ещё не соответствуют уровню пакета, который централизованно управляет полномочиями.
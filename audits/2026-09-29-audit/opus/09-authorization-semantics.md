# 09 — Семантика авторизации (нормативно)

Решения: [D05](02-decisions.md#d05), [D15](02-decisions.md#d15)–[D20](02-decisions.md#d20), [D24](02-decisions.md#d24)–[D27](02-decisions.md#d27),
[D29](02-decisions.md#d29), [D31](02-decisions.md#d31). Этот файл — спецификация для opus-исполнителя: реализация
движка сверяется с таблицами ниже тестами-свойствами (`tests/Unit/Kernel/*`, [14](14-verification.md)).

## 1. Алгоритм `decide(AccessRequest $r)`

```
input:  subject S (SubjectRef), key K (PermissionKey), context C? (ContextRef|null), resource R?, trace?
state:  T = StateToken (один на оценку / на decideMany)

1. realm := K.realm();  Realm := registry.get(realm)                     # неизвестный → UnknownRealmException
2. if not catalog.owns(K) → NotApplicable                                  # Gate: null
3. C' := C ?? (CurrentContext if Realm.contextPolicy.acceptsType(current)) ?? global
4. contexts := applicable(Realm.contextPolicy, C')                         # §2
   if contexts = DENY(reason) → Deny(reason)
5. if SuperadminPolicy.isSuperadmin(S, realm):
       for each constraint Q in constraints(Realm, r) where !Q.bypassable():  # §4
           res := Q.check(r) ; if res = fail → Deny(ConstraintFailed, Q.key)
       → Allow(Superadmin)
6. set := ⋃ source.contributions(S, realm, contexts)                      # кэшируется по (S, realm, contexts, T)
   if not set.covers(K) → Deny(NotGranted)
7. for each constraint Q in constraints(Realm, r) (порядок реестра, затем realm):
       res := Q.check(r)            # исключение → Deny(ConstraintError, Q.key), лог
       if res = fail → Deny(ConstraintFailed, Q.key)
8. → Allow(Granted, contributions = trace ? matching(set, K) : [])
```

Свойства (проверяются property-тестами):

- **P1 Монотонность вкладов:** добавление назначения/гранта никогда не превращает Allow в Deny.
- **P2 Обязательность constraints:** Allow ⇒ каждый применимый constraint вернул `pass`/`abstain` (или был
  `bypassable` при superadmin).
- **P3 Изоляция realm:** решение по ключу realm X не зависит от назначений в realm Y ≠ X (кроме `*:superadmin`).
- **P4 Изоляция контекста:** при `isolated`/`required` решение в контексте C не зависит от контекстных назначений
  в C₂ ≠ C.
- **P5 Детерминизм:** одинаковые `(r, T, now)` → одинаковое решение; `decideMany` = поэлементный `decide` на одном T.
- **P6 Инъективность идентичности:** `ContextRef a ≠ b` ⇒ `a.key() ≠ b.key()` и digest-ключи кэша различны (закрывает P07).
- **P7 Срок:** назначение с `expiresAt ≤ now` не вносит вклад; `PermissionSet.validUntil = min(expiresAt)`; кэш не
  отдаёт набор после `validUntil`.

## 2. Политика контекстов realm

| Политика | Запрос без контекста | Запрос в контексте C (тип принят) | Тип C не принят realm |
|---|---|---|---|
| `inherit(types…)` (по умолчанию для realm с контекстами) | `{global}` | `{global, C}` | `Deny(ContextNotAccepted)` |
| `isolated(types…)` | `{global}` | `{C}` | `Deny(ContextNotAccepted)` |
| `required(types…)` | `Deny(ContextRequired)` | `{global, C}` | `Deny(ContextNotAccepted)` |
| `none()` (по умолчанию для realm без `contexts()`) | `{global}` | `{global}` + предупреждение в explain | контекст игнорируется |

`requireMembership()` добавляет встроенный constraint `azguard/context-membership` (не `bypassable`) для запросов в
контексте: глобальная роль действует в контексте **только** для членов контекста.

Пример («workspace-SaaS + админка»), решает N07:

```php
AppRealm:   ->contexts(ContextPolicy::inherit('workspace')->requireMembership())
AdminRealm: (без contexts) → none()
```

| Субъект | Назначение | Запрос | Итог |
|---|---|---|---|
| Анна | `app:editor` в `workspace:1` | `app.docs.update` в `workspace:1` | Allow |
| Анна | то же | `app.docs.update` в `workspace:2` | Deny(NotGranted) |
| Борис | `app:editor` глобально, член `workspace:2` | `app.docs.update` в `workspace:2` | Allow |
| Борис | то же, **не** член `workspace:3` | `app.docs.update` в `workspace:3` | Deny(ConstraintFailed, `azguard/context-membership`) |
| Вера | `admin:operator` глобально | `admin.users.ban` (ambient `workspace:1`) | Allow (контекст игнорируется realm `admin`) |

## 3. Superadmin

| Источник | Радиус | Обходит отсутствие гранта | Обходит constraints |
|---|---|---|---|
| роль realm X с `is_superadmin` | только X | да | только `bypassable()` |
| `*:superadmin` (`platform_role`) | все realm | да | только `bypassable()` |
| шаблон `x.**` в роли/гранте | все ключи X | — (это обычный вклад) | нет |
| `superadmin.bypass_constraints = true` | — | — | все |

`isSuperadmin(realm)` = истина только для первых двух строк (не для `x.**`) — два «всё» больше не смешиваются (N13).
Gate: при `gate.superadmin_scope = all` мост отвечает `true` и на чужие abilities (Laravel-style super-user), только
для `*:superadmin`.

## 4. Constraints: применимость и порядок

`constraints(Realm, r)` = глобальные из `azguard.authorization.constraints` (порядок массива) + ключи
`RealmBuilder::constraints()` + встроенный membership (если включён) — дубликаты удаляются с сохранением первого
вхождения; затем фильтр `appliesTo(r, Realm)`. Результат `abstain` не влияет. Отпечаток списка входит в
`StateToken::policyFingerprint`.

## 5. Gate-мост

```
Gate::before($user, $ability, $arguments):
  if !enabled → null
  key := PermissionKey::tryFrom($ability)          # enum case → from(); иная строка → null
  if key = null or realm неизвестен → null         # чужая ability: Laravel-политики работают как без AzGuard
  context := first($arguments) если ContextRef|Model-контекст типа, принятого realm; иначе null (ambient)
  resource := остальные аргументы (для constraints)
  d := decide(AccessRequest(S($user), key, context, resource))
  mode = authoritative: return d.toGateResult()    # Allow→true, Deny→false, NotApplicable→null
  mode = additive:      return d.allowed() ? true : null
```

Стоимость для чужой ability: разбор строки + `isset` по индексу realm-префиксов — O(1), без запросов (D44;
сейчас O(каталог), N17).

## 6. Кэш и консистентность

| Слой | Ключ | Инвалидация | Срок |
|---|---|---|---|
| request (scoped `PermissionSetCache`) | `digest(S, realm, contexts, T)` | новый `T` | lifecycle запроса/job |
| межзапросный (`cache.store`) | тот же digest + `generation` | новый `T` (ревизия/отпечаток/generation) | `min(ttl, validUntil)` |
| ревизия (`StateRevision`) | — | собственная мутация процесса | `state_refresh`: request или check |

**Гарантия отзыва (документируется дословно):**

> После commit отзыва (транзакция `AccessManager`) любая проверка, **начавшая** request/job после этого commit,
> видит отзыв — при `database.reads = primary` и `cache.state_refresh = request`. При `state_refresh = check` —
> любая проверка, начавшаяся после commit. Проверка, уже прочитавшая состояние до commit, может завершиться со
> старым результатом. При `database.reads = default` гарантия ослабляется до задержки репликации.

Read-your-writes: процесс, выполнивший мутацию, продвигает свою ревизию сразу после commit; внутри незакоммиченной
мутации кэш обходится (только для этого соединения и этого процесса — не для любой транзакции, P10b).

## 7. Пакетная оценка

`decideMany(requests)`: группировка по `(S, realm)`; для каждой группы — одна загрузка назначений по объединению
всех контекстов группы (`context_key IN (…)`, батчи по 100); `now` и `T` общие; порядок результатов = порядок входа.
Vaulter (D43) вызывает с N узлами одного drive: один контекст, одна загрузка.

## 8. Видимость (`visibleTo`)

```
constrain(Q over model M, S, K):
  realm := K.realm(); type := morph(M)
  if superadmin(S, realm) → Q
  if Realm.contextPolicy не принимает type → Q whereRaw('1 = 0')          # явная ошибка конфигурации в explain
  if глобальный вклад покрывает K и политика inherit/required → Q          # видно всё
  roles := {role_id : права роли покрывают K}   (code — из реестра, DB — azg_role_permissions; кэш по T)
  Q whereExists (azg_role_assignments a: a.subject=S ∧ a.context_type=type ∧ a.context_id = M.key ∧ a.role_id ∈ roles ∧ живое)
    OR whereExists (azg_grants g: g.subject=S ∧ g.context_type=type ∧ g.context_id = M.key ∧ g.permission покрывает K ∧ живое)
  constraints с appliesTo → применяются к элементам после выборки (документируется: visibleTo не заменяет decide для
  resource-constraints)
```

Покрытие шаблоном в SQL: для гранта с точным ключом — равенство; с шаблоном — перечисление шаблонов субъекта,
покрывающих K, в PHP (их мало) и `permission IN (…)`. Нет субъекта (очередь без актора) → пусто, без чтения `Auth`.

## 9. Объяснение

`explain(r)` исполняет **ту же** оценку с `trace = true` и возвращает `Explanation`: `decision`, `realm`,
`contextsConsidered`, `contributions` (что совпало и что нет), `constraints: list<{key, result, reason}>`,
`superadmin: bool`, `gate: true|false|null` (что ответил бы мост), `state`. Команда `azguard:explain` печатает то же
(`--json`). Никаких повторных запросов к источникам после решения (C05).

## 10. Среда исполнения

| Среда | Гарантия |
|---|---|
| HTTP (FPM) | scoped-сервисы на запрос; ambient-контекст из `azguard.context` |
| Octane | контейнер сбрасывает scoped; singleton-реестры заморожены и не содержат request-состояния (arch-тест: singletons без mutable props после freeze) |
| Queue | нет ambient-субъекта и контекста; job передаёт `SubjectRef`/`ContextRef` явно; `withinContext()` восстанавливает в `finally` |
| Console | как queue; пишущие команды — `asSystem('cli: …')` |
| Fibers/корутины | **не поддерживается** ambient-контекст (scoped ≠ fiber-local); явный контекст в запросе работает. Документируется |

## 11. Контрпримеры: что именно исключает каждое решение

| Решение | Опасное поведение сейчас | Почему невозможно после |
|---|---|---|
| D05 | `can('admin.users.ban')` на запросе панели `app` → отказ; `hasPermission('admin.x')` по набору `app` (P01c, P09) | realm берётся из ключа; набора «другой панели» не существует |
| D07 | грант `(workspace,"a:7")` действует в `("workspace:a",7)` (P07) | `:` запрещён в типе, ключ инъективен |
| D13 | DB-роль работает глобально, но не в контексте (P08) | один источник читает все назначения одинаково |
| D14 | переименование класса роли роняет проверки держателей (P02) | идентичность — ключ; неразрешимый класс → пустая роль + doctor |
| D15 | строгая стратегия для `app` обнуляет `admin` (P06) | политика — на realm |
| D16 | исключение между `set()` и `try` оставляет чужой контекст (C02) | контекст — аргумент; `withinContext` ставит внутри `try` |
| D19 | роль `app` с `*` — superadmin в `admin` (P01a); `grant('*')` из публичного API (P14) | `*` невалиден; superadmin — флаг роли realm |
| D22/D23 | UI пишет `class_name`, любой редактор выдаёт что угодно себе (N02) | запись только через `AccessManager` с делегированием и без эскалации |
| D24 | revoke не виден из-за реплики (C03); N запросов ревизии на N проверок (P10) | primary-чтения; ревизия раз в lifecycle |
| D26 | resolver из конфига игнорируется Gate (P03) | нет сменяемого resolver; источники и constraints едины для всех входов |
| D31 | очередь/пользователь без назначений видят все строки; две строки → ноль (P04) | явный фильтр, fail-closed, OR по назначениям |

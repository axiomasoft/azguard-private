# 09 — Смысл проверки прав (нормативно)

Решения: [D05](02-decisions.md#d05), [D15](02-decisions.md#d15)–[D20](02-decisions.md#d20), [D24](02-decisions.md#d24)–[D27](02-decisions.md#d27),
[D29](02-decisions.md#d29), [D31](02-decisions.md#d31), [D48](02-decisions.md#d48). Простое объяснение — [00 §4](00-overview.md#4-как-проходит-проверка--пайплайн-доступа).
Этот файл — спецификация движка; реализация сверяется с таблицами ниже тестами-свойствами ([14](14-verification.md)).

## 1. Пайплайн доступа: алгоритм `decide(AccessRequest $r)`

```
вход:   S (SubjectRef), K (PermissionKey), C? (ContextRef|null), R? (ресурс), trace?
панель: P := panels.get(K.panel())                                  # неизвестная → UnknownPanelException
снимок: T := StateToken(P)                                          # один на оценку / на decideMany

0. владение:   if not P.catalog.owns(K) → NotApplicable              # Gate: null
1. подготовка: r := fold(P.prepare-пайпы, r)                         # могут дополнить запрос (контекст из ресурса)
               C' := r.context ?? CurrentContext (если тип принят P) ?? global
               contexts := applicable(P.contextPolicy, C')           # §2; может дать Deny(ContextRequired|ContextNotAccepted)
2. суперадмин: if P.superadminPolicy.isSuperadmin(S):
                  for Q in restrictions(P, r) where !Q.bypassable(): if Q.check = deny → Deny(RestrictionDenied, Q)
                  → Allow(Superadmin)
3. сбор:       set := ⋃ source.contributions(S, contexts) по источникам панели       # кэш по (P, S, contexts, T)
               исключение источника → Deny(SourceError, source)                    # не «пропустить источник»
               if not set.covers(K) → Deny(NotGranted)
4. ограничения: for Q in restrictions(P, r) (порядок регистрации):
                  исключение → Deny(RestrictionError, Q); deny → Deny(RestrictionDenied, Q); abstain/pass → дальше
5. решение:    Allow(Granted, contributions = trace ? matching(set, K) : [])
6. наблюдение: for O in P.observers: O.observe(r, decision)          # исключение → лог, решение не меняется
```

Свойства (property-тесты):

- **P1 Монотонность сбора:** новая выдача не превращает Allow в Deny.
- **P2 Обязательность ограничений:** Allow ⇒ каждое применимое ограничение дало pass/abstain (или было `bypassable`
  при суперадмине).
- **P3 Независимость панелей:** решение по ключу панели X не зависит от выдач, плагинов и настроек панели Y ≠ X
  (кроме общих плагинов, явно подключённых к обеим, например `GlobalSuperadminPlugin`).
- **P4 Изоляция контекста:** при `isolated`/`required` решение в контексте C не зависит от выдач в C₂ ≠ C.
- **P5 Детерминизм:** одинаковые `(r, T, now)` → одинаковое решение; `decideMany` = поэлементный `decide` на одном T.
- **P6 Инъективность:** `ContextRef a ≠ b` ⇒ `a.key() ≠ b.key()` и ключи кэша различны (закрывает P07).
- **P7 Срок:** выдача с `expiresAt ≤ now` ничего не даёт; `PermissionSet.validUntil = min(expiresAt)`; кэш не отдаёт
  набор после `validUntil`.
- **P8 Безопасность шагов:** ни один пайп шагов 1, 4, 6 не может превратить Deny в Allow; только источники шага 3
  добавляют права.

## 2. Политика контекстов панели

| Политика | Запрос без контекста | Запрос в контексте C (тип принят) | Тип C не принят панелью |
|---|---|---|---|
| `inherit(types…)` | `{global}` | `{global, C}` | `Deny(ContextNotAccepted)` |
| `isolated(types…)` | `{global}` | `{C}` | `Deny(ContextNotAccepted)` |
| `required(types…)` | `Deny(ContextRequired)` | `{global, C}` | `Deny(ContextNotAccepted)` |
| `none()` (по умолчанию) | `{global}` | `{global}` + предупреждение в объяснении | контекст игнорируется |

`requireMembership($membership)` добавляет ограничение `azguard/context-membership` (не `bypassable`) для запросов в
контексте: глобальная роль действует внутри магазина **только** для членов магазина.

Пример — магазин с админкой (решает N07):

```php
SitePanelProvider:  ->contexts(ContextPolicy::inherit('store')->requireMembership(StoreMembership::class))
AdminPanelProvider: (контексты не заданы) → none()
```

| Субъект | Выдача | Запрос | Итог |
|---|---|---|---|
| Анна | `site:store-manager` в `store:1` | `site.orders.cancel` в `store:1` | Allow |
| Анна | то же | `site.orders.cancel` в `store:2` | Deny(NotGranted) |
| Борис | `site:store-manager` глобально, член `store:2` | `site.orders.cancel` в `store:2` | Allow |
| Борис | то же, **не** член `store:3` | `site.orders.cancel` в `store:3` | Deny(RestrictionDenied, `azguard/context-membership`) |
| Вера | `admin:support` глобально | `admin.orders.refund` (текущий контекст `store:1`) | Allow (панель `admin` контексты не использует) |

## 3. Суперадмин

| Источник | Радиус | Обходит отсутствие прав | Обходит ограничения |
|---|---|---|---|
| роль панели P с `is_superadmin` (встроенная `{panel}:superadmin` или своя) | только P | да | только `bypassable()` |
| `GlobalSuperadminPlugin`, подключённый к панелям P₁…Pₙ | только эти панели | да | только `bypassable()` |
| шаблон `p.**` в роли/прямом праве | все права P | — (обычный вклад) | нет |
| `superadmin(bypassRestrictions: true)` на панели | — | — | все |

`isSuperadmin(panel)` истинно только в первых двух строках. Gate при `superadmin_scope = all` отвечает `true` и на
чужие abilities — только для суперадмина этой панели запроса (панель берётся из текущей панели маршрута).

## 4. Ограничения: состав и порядок

`restrictions(P, r)` = ограничения, зарегистрированные провайдером панели (`->restrict()`), затем плагинами — в порядке
подключения; встроенное ограничение членства — первым, если включено; дубликаты ключей → ошибка сборки; затем
фильтр `appliesTo(r, context)`. Порядок и состав входят в `StateToken::policyFingerprint`.

## 5. Gate

```
Gate::before($user, $ability, $arguments):
  key := PermissionKey::tryFrom($ability)             # enum → from(); строка без зарегистрированной панели → null
  if key = null → null                                # чужая ability: Laravel-политики работают как без AzGuard
  P := panels.get(key.panel()); if !P.catalog.owns(key) → null
  subject := $user (или субъект guard'а панели, если $user = null и маршрут в azguard.panel)
  context := первый аргумент, если это ContextRef/модель типа, принятого P; иначе текущий контекст
  d := P.decide(AccessRequest(subject, key, context, resource: остальные аргументы))
  P.gate.mode = authoritative → d.toGateResult()  (Allow→true, Deny→false)
  P.gate.mode = additive      → d.allowed() ? true : null
```

Стоимость для чужой ability: разбор строки + `isset` по индексу префиксов панелей — O(1), без запросов (сейчас —
линейный обход каталога, N17).

## 6. Кэш и консистентность

| Слой | Ключ | Инвалидация | Срок |
|---|---|---|---|
| request (scoped) | `digest(P, S, contexts, T)` | новый `T` панели | lifecycle запроса/job |
| межзапросный (`cache.store` панели) | тот же digest + `generation` | новый `T` (версия панели, отпечаток политики, generation) | `min(ttl, validUntil)` |
| версия панели | — | собственная мутация процесса | `state_refresh` панели: request или check |

**Гарантия отзыва (документируется дословно):**

> После успешного сохранения отзыва (операция менеджера доступа панели) любая проверка **этой панели**, начавшая
> request/job после сохранения, видит отзыв — при `consistency(reads: primary, refresh: request)`. При
> `refresh: check` — любая проверка, начавшаяся после сохранения. Проверка, уже прочитавшая состояние до сохранения,
> может завершиться со старым результатом. При `reads: default` гарантия ослабляется до задержки репликации.

Read-your-writes: процесс, выполнивший изменение, продвигает свою версию сразу после commit; внутри незакоммиченного
изменения кэш этой панели обходится (только для этого процесса — не при любой транзакции соединения, P10b).

## 7. Пакетная оценка

`decideMany(requests)` группирует по `(S, P)`; одна загрузка выдач на объединение контекстов группы
(`context_key IN (…)`, батчи по 100); `now` и `T` общие; порядок результатов = порядок входа. Ограничения исполняются
на каждый запрос (они дешёвые по контракту; ограничение с IO объявляет пакетный режим `BatchRestriction::checkMany()`).

## 8. Видимость (`visibleTo`)

```
constrain(Q по модели M, S, K):
  P := панель K; type := morph(M)
  if суперадмин(S, P) → Q
  if P.contextPolicy не принимает type → Q whereRaw('1 = 0')         # + пояснение в explain
  if глобальный вклад покрывает K и политика inherit/required → Q    # видно всё
  roles := {role_id : права роли покрывают K} (code — из реестра, DB — role_permissions; кэш по T)
  Q whereExists(role_assignments a: a.panel = P ∧ a.subject = S ∧ a.context_type = type ∧ a.context_id = M.key ∧ a.role_id ∈ roles ∧ живая)
    OR whereExists(direct_grants g: g.panel = P ∧ g.subject = S ∧ g.context_type = type ∧ g.context_id = M.key ∧ g.permission ∈ шаблоны S, покрывающие K ∧ живая)
    OR (для внешних источников) contextsCovering(S, K, type) → whereIn(M.key, …) либо `Volatile`-источник → предупреждение: видимость неполна
  ограничения с appliesTo → к элементам после выборки (visibleTo не заменяет decide для ресурсных ограничений)
```

Нет субъекта (очередь без актора) → пусто, без чтения `Auth`. Таблицы — из хранилища панели P.

## 9. Объяснение

`explain(r)` исполняет **ту же** оценку с `trace = true` и возвращает `Explanation` по шагам: подготовка (что
дополнено и кем), применимые контексты, суперадмин (кем признан), вклады (какой источник, роль, контекст, срок,
поля решения), ограничения (`{key, result, reason}`), наблюдатели, итог Gate (`true`/`false`/`null`), `state`.
`azguard:explain` печатает то же (`--json`). Повторных запросов к источникам нет (C05).

## 10. Среда исполнения

| Среда | Гарантия |
|---|---|
| HTTP (FPM) | scoped-сервисы на запрос; текущая панель — `azguard.panel`, текущий контекст — резолверы панели |
| Octane | контейнер сбрасывает scoped; реестры панелей заморожены и не хранят request-состояния (arch-тест) |
| Queue | нет текущего субъекта и контекста; job передаёт `SubjectRef`/`ContextRef` явно; `withinContext()` восстанавливает в `finally` |
| Console | как queue; пишущие команды — `asSystem('cli: …')` |
| Fibers/корутины | текущий контекст **не** поддерживается (scoped ≠ fiber-local); явный контекст в запросе работает |

## 11. Что именно исключает каждое решение

| Решение | Опасное поведение сейчас | Почему невозможно после |
|---|---|---|
| D05 | `can('admin.users.ban')` на запросе другой панели → отказ; `hasPermission('admin.x')` по набору другой панели (P01c, P09) | панель берётся из ключа |
| D07 | грант `(workspace,"a:7")` действует в `("workspace:a",7)` (P07) | `:` запрещён в типе, ключ инъективен |
| D13 | DB-роль работает глобально, но не в контексте (P08) | один источник читает все выдачи одинаково |
| D14 | переименование класса роли роняет проверки держателей (P02) | идентичность — ключ; неразрешимый класс → пустая роль + doctor |
| D15 | строгая стратегия одной панели обнуляет другую (P06) | политика — на панели |
| D16 | исключение между `set()` и `try` оставляет чужой контекст (C02) | контекст — аргумент; `withinContext` ставит внутри `try` |
| D19 | роль с `*` одной панели — суперадмин в другой (P01a); `grant('*')` из публичного API (P14) | `*` невалиден; суперадмин — флаг роли панели или явно подключённый плагин |
| D22/D23 | UI пишет `class_name`, любой редактор выдаёт что угодно себе (N02) | запись только через пайплайн изменений с делегированием |
| D24 | отзыв не виден из-за реплики (C03); N запросов версии на N проверок (P10) | primary-чтения; версия раз в lifecycle |
| D48 | сменяемый resolver игнорируется Gate (P03) | нет сменяемого resolver; все входы идут через один пайплайн панели |
| D31 | очередь/пользователь без назначений видят все строки; две строки → ноль (P04) | явный фильтр, fail-closed, OR по выдачам |

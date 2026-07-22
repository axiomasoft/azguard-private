# Приоры Fable: fluent API / DX / интеграционная поверхность azguard

> Слой 2. Авторский синтез планирующей модели (fable, design pass 1) — «важные мысли»,
> закладываемые ДО аудита. Внешние тезисы верифицированы RAG'ом в том же прогоне —
> выжимка: `findings/P0-rag-fluent-dx-preseed.md`; P0.1 добирает первоисточники
> (Filament-конвенции через context7). Оси P0.2–P0.5 проверяют применимость к коду.
> Приор ≠ решение: решения — только через REGISTER/бэклог/D#. Фактура репо:
> findings/recon-api-surface-2026-07-18.md, findings/recon-test-ci-2026-07-18.md.

## A. Интеграционная поверхность (акцент брифа)

1. **Один ментальный вход — `AzGuard::fake()`-класс Testing DX.** Современный стандарт
   Laravel-пакетов — фасадный fake с ассерциями (`Storage::fake()`, `Event::fake()` +
   `assert*`). У нас есть FakeAzGuardUser/FakeGrantSource, но нет `AzGuard::fake()` с
   `assertGranted()/assertDenied()/assertChecked()` — потребитель собирает фейки руками.
   RAG:✅ 2026-07-18 «fake-фасад + ассерции — стандарт spatie 2024–2026 (Pdf/Screenshot/
   Health/Markdown::fake)» — findings/P0-rag-fluent-dx-preseed.md §1.
2. **Headless-путь — first-class.** INTEGRATION_FEEDBACK п.4 закрыт «только доками»:
   потребитель без Filament должен пройти install→User-трейт→Gate→abilities без чтения
   Filament-глав. Кандидат: отдельный quick-start «headless» + `guard:doctor --headless`.
   RAG:— (repo-grounded: findings/recon-api-surface §2).
3. **Словарь поверхности: panel / guard / context / scope.** Четыре пересекающихся
   термина в одном API — самый дорогой налог на онбординг. Аудит оси A обязан построить
   таблицу «термин → сущность → где виден потребителю» и найти сливаемые. RAG:—
   (repo-grounded: config az-guard.php: panels/strict_panels + context-пакет + ScopeInterface).
4. **Полиморфизм входных типов повсюду.** Всё, что принимает permission/role, должно
   принимать `string|PermissionKey|BackedEnum` единообразно (у Filament-панелей enum'ы —
   уже норма); сейчас единообразие не гарантировано — проверить по фасаду/трейтам/
   middleware-параметрам. RAG:✅ 2026-07-18 «string|BackedEnum — канон границы: spatie 6.x
   (все проверки/присвоения), Laravel Authorize::using(enum) PR #52679» —
   findings/P0-rag-fluent-dx-preseed.md §2; применимость к коду — ось B.
5. **Middleware-параметры — строковый DSL** (`azguard.check:...`) — проверить читаемость
   и симметрию с фасадом; кандидат: именованные статические конструкторы
   (`CheckPermission::using(...)`) как у Laravel `Authorize::using()`. RAG:✅ 2026-07-18
   «static using() — конвенция: spatie Role/Permission/RoleOrPermissionMiddleware::using +
   Laravel 10.9+ Authorize::using, интеграция HasMiddleware» —
   findings/P0-rag-fluent-dx-preseed.md §2. У azguard только строковые алиасы — гэп.

## B. Fluent-грамматика (ось B / P2)

6. **Грамматика «предложением»:** цепочка читается как фраза —
   `AzGuard::grant('orders.edit')->to($user)->on('admin')->until($date)` и
   `AzGuard::forUser($user)->in('admin')->can('orders.edit')`. Сейчас grant-API —
   позиционno-аргументное (`grant($user, $key, $panelId)`); `ContextGrantBuilder` (F14)
   уже fluent — грамматику надо унифицировать core↔context, а не иметь две. RAG:—
   (repo-grounded: findings/recon-api-surface §1, §2).
7. **Фасад 18 @method — плоский и широкий.** Приор СКОРРЕКТИРОВАН RAG'ом: у
   spatie/laravel-permission широкого фасада нет вовсе — локус API Laravel-native
   (трейт User + модели + Gate/`can`), фасад не центр. Для azguard: трейт+Gate — главный
   путь потребителя, фасад — УЗКИЙ оркестровый вход (panels/catalog/grants, fluent-корни
   forUser/grant); ширину 18 @method мерить против этого. Резать без cut-line P3 нельзя —
   сначала аудит реального использования (grep по vaulter-мосту и docs-примерам).
   RAG:✅ 2026-07-18 «локус API spatie — трейт/модели/Gate, не фасад» —
   findings/P0-rag-fluent-dx-preseed.md §2.
8. **Registration-API против config-массивов.** Всё swappable сейчас через config-ключи
   (manager/resolver/matcher/...). Приор: config — для классов, fluent-методы
   SP/плагина — для поведения (`AzGuard::panels(...)`, `->strict()`); проверить, где
   config-ключ фактически «программирование строками». RAG:✅ 2026-07-18 «fluent для
   поведения, config для wiring/дефолтов, „no DSL in config“ — spatie 2024–2026» —
   findings/P0-rag-fluent-dx-preseed.md §1. Filament plugin-конвенции — покрыты только
   обзорно, первоисточник добирает P0.1 (context7, ключ активен со следующей сессии).
9. **Immutable builders.** Новые fluent-объекты — `final readonly` + with-методы (у нас
   уже канон F49 для Values) — избегать мутабельных builder'ов с отложенным `save()`
   без явного терминального глагола (`->apply()`/`->give()`). RAG:— (repo-grounded:
   ArchTest toBeFinal/toBeReadonly для Registry\Values).

## C. Надёжность как свойство API (ось C / P4)

10. **Явная семантика «нет панели».** T1 закрыт аддитивно (D5 TAILS): query-scope без
    установленной панели не изолирует. Это должно стать ЯВНЫМ контрактом API (метод/
    режим `strict`), а не сноской в доке — иначе тихая дыра у потребителя. RAG:—
    (repo-grounded: findings/recon-api-surface §2).
11. **Epoch-кэш: unbounded рост + отсутствие кросс-процессного теста** (T6 follow-up) —
    кандидат в Blocker-волну P1, тест — P4.4. RAG:— (repo-grounded: REMAINDER_REPORT).
12. **Wildcard-грамматика `**` (F22)** — раз пре-1.0 свобода, решить СЕЙЧАС (P2.3), а не
    тащить deprecate-цикл в 1.0. RAG:— (repo-grounded: ARCHITECT_REVIEW F22).

## D. Что НЕ делать (анти-приоры)

13. Не превращать фасад в DSL-комбинатор «всё fluent ради fluent»: прямые предикаты
    (`can`, `isSuperAdmin`) должны остаться одним вызовом. Инварианты
    ARCHITECT_REVIEW §6 (union-only, курированный frontend, контракты только на реальных
    швах) — граница любых редизайнов. RAG:— (repo-grounded: ARCHITECT_REVIEW §6).
14. Не генерировать TS-типы/SDK для frontend abilities в этом плане — курированный
    список остаётся; генерация — отдельная тема вне 0.3.0 (YAGNI-гейт).

## Как потребляется

- Тезисы 1, 4, 5, 7, 8 верифицированы preseed-RAG'ом (findings/P0-rag-fluent-dx-preseed.md);
  P0.1 добирает первоисточники: Filament plugin-конвенции (context7), spatie docs
  по цитируемым паттернам — и пере-проверяет вердикты preseed.
- P0.2 (ось A): тезисы 1–5; P0.3 (ось B): 6–9; P0.4 (ось C): 10–12; P0.5: словарь (3).
- P2 наследует подтверждённое через research/02-backlog.md (D7), не напрямую отсюда.

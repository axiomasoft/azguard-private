---
id: D7
date: 2026-10-02
status: accepted
item: P1
items: [P1, P1.3, P1.4, P1.6, P1.7, P1.8, P2]
supersedes: []
superseded_by: null
---
# D7 — Находки Review P1 исправляет пункт P1.8 в той же сессии; имена исключений значений — из `03`

**Actor:** owner (сообщение 2026-10-02 «исправляй все в этой сессии») / Claude Opus 5.5 (frontier)
**Evidence:** `findings/P1-review.md` (verdict RED: F1 major, F2/F3 minor); RAG:— `03-glossary-and-renames.md`:383-385
(`InvalidSourceContributionException`, `ConsistencyException` — стабильный snake_case code); `05-php-api.md` §10;
`02-decisions.md` D37; D3 (найденное review исправляется до закрытия фазы); D6 п.4.

## Solution

1. **Маршрут исправления.** Owning items P1.3, P1.4, P1.6 терминальны, а повторный допуск терминального пункта
   (`repeat-admission/v1`) требует полного дизайна плана, которого при поэтапной детализации (D3) нет: P2–P8 —
   скелеты. Поэтому находки F1–F3 исправляет новый пункт **P1.8** фазы P1 (solo, frontier/high) в сессии Review P1
   по прямому указанию владельца. Повторная проверка — только находки F1–F3 и гейты D4; результат дописывается в
   `findings/P1-review.md` разделом «Повторная проверка». Фаза P1 закрывается после GREEN P1.8.
2. **F1 (P1.4).** Правило «no debug calls» становится token-сканом всех `packages/*/src` по тому же помощнику, что
   проверка вызовов фреймворка в Kernel: одна реализация `AzGuard\Tests\Arch\SourceScan` (файлы `src`, вызовы
   глобальных функций), без копий в тестах. Список функций прежний: `dd`, `dump`, `ray`, `var_dump`, `print_r`.
3. **F2 (P1.3).** Нарушения инвариантов значений Kernel бросают исключения иерархии D37, а не SPL:
   - `ConsistencyException` (`consistency`, родитель `AuthorizationEngineException`) — effect не допускает reason
     (`Decision`), решения одного набора несут разные токены одного состояния (`DecisionSet`);
   - `InvalidSourceContributionException` (`invalid_source_contribution`, родитель `AuthorizationEngineException`) —
     вклад расширения в решение нарушает контракт: роль выдачи из другой панели и поля не plain data (`Grant`,
     `RoleContribution`), причина отказа ограничения не snake_case (`RestrictionResult`);
   - `InvalidIdentityException` (D6 п.4) — пустые части или отрицательные счётчики `CodeStateToken`/`StateToken`
     (рядом с `InvalidPanelIdException` той же фабрики).
   Код — имя класса без `Exception` в snake_case, как в таблице 05 §10.
4. **F3 (P1.6).** Phase Context `phases/P2/P2.md` принимает от P1.6: проверку переопределённых `BaseRole::key()`/
   `formerKeys()`, дубликатов и коллизий `formerKeys` между ролями панели при компиляции панели (P2.5/P2.8).

## Why

D3 требует исправить найденное до закрытия фазы; владелец потребовал сделать это в этой сессии. Отдельный пункт
сохраняет историю терминальных P1.3/P1.4/P1.6 неизменной и даёт исправлению собственные Files и Validation.
Имена F2 уже есть в `03`, поэтому новые имена не вводятся; SPL-исключение без `code()` нарушало D37.

## Consequences

P1.8 — последний пункт P1 и owning item находок F1–F3. Review P1 (P1.7) не повторяется целиком: повторная
проверка ограничена F1–F3 (D3: re-review проверяет только находки). Tests, ожидавшие `InvalidArgumentException`,
переходят на классы D37; `api-manifest.json` ядра получает два класса.

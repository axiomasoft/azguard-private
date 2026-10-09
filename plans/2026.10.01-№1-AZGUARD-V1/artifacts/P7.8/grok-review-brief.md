# Независимое read-only ревью фазы P7 (AzGuard 1.0, Filament) — PLAN2 P7.8

Ты — независимый ревьюер. Репозиторий: `/home/vostrikov/projects/packages/azguard` (ветка main, HEAD = последний коммит P7.7).
Режим строго read-only: не изменяй файлы репозитория (ядро ОС запрещает запись вне /tmp, ~/.grok и vendor/).
Можно читать всё, запускать команды и тесты. Свои временные тесты/скрипты клади только в /tmp.
Исправления не делаешь: находишь, воспроизводишь и останавливаешься.

## Объект ревью

Вся фаза P7 «Filament по схеме панели»: коммиты от базы `1b71f18370b48c90fa7817b25fac6e99bf4863da` до HEAD
(`git log --oneline 1b71f18..HEAD`, `git diff 1b71f18..HEAD --stat` — 177 файлов). Пункты:
- P7.1 плагин `AzGuardPlugin`, конфиг, ключи `FilamentKeys`, `FilamentSource`, `FilamentTenantResolver`, admission EnterPanel;
- P7.2 `FilamentGate`, трейты `Concerns\Authorizes{Resource,RelationManager,Page,Widget}`, bulk, экспорт (`Exports\*`);
- P7.3 ядро `Directories\PanelDirectories` + `PanelAccess::directories()`, `Editors\TargetSelector`, `RoleResource`, `PermissionResource`;
- P7.4 ядро `GrantFilter` (expiresBefore/grantedBy), `RoleGrantResource`, `PermissionGrantResource`, `Editors\ListGrants`, `Editors\GrantEditor`, `Actions\ExplainAction`, `Forms\SchemaFields`;
- P7.5 `Contracts\FilamentFormExtension`, свои поля из схемы в формах выдач;
- P7.6 ядро `Diagnostics\PanelOverview`, `Pages\PanelsPage`, `Pages\DoctorPage`;
- P7.7 `azguard:filament:generate`, `Attributes\ForFilament`, `Diagnostics\FilamentDefinitionsCheck`, расширение `azguard:make:permission` (`--authority`, `--case`).

Код: `packages/filament/src/**`, изменения ядра в `packages/core/src/**`, тесты `tests/Feature/Filament/**`,
`tests/Acceptance/Crm/FilamentTest.php`, `tests/Arch/**`, фикстуры `tests/Fixtures/Filament/**`, `tests/Fixtures/Crm/Filament/**`.

## Нормативные источники (прочитай)

1. Модель фазы: `plans/2026.10.01-№1-AZGUARD-V1/knowledge/P7-filament-model.json` — все разделы: предпосылки Filament F1–F12,
   ключи, **инварианты I1–I10**, пробелы ядра G1–G5, раскладка, **карта приёмки** (раздел Acceptance).
2. Решение фазы: `plans/2026.10.01-№1-AZGUARD-V1/decisions/D27.json`.
3. Итоги исполнения и отклонения пунктов: `plans/2026.10.01-№1-AZGUARD-V1/knowledge/findings-P7-execution.json`.
4. Досье: `audits/2026-09-29-audit/opus/11-filament.md` (целиком); `audits/2026-09-29-audit/opus/14-verification.md` — строки
   V23, V24, V25, V26, V61, V62, V63, V75, V76, V95, V96, V102; `audits/2026-09-29-audit/opus/17-crm-acceptance-tests.md` — R24, R25,
   R28, R34, R36, R37, R38, R39, R40.
5. `tests/Acceptance/Crm/README.md` — честность статусов R-кейсов P7.
6. Arch-гейты: `tests/Arch/ApiManifestTest.php`, `tests/Arch/FilamentWritesArchTest.php`, манифесты `packages/*/api-manifest.json`.

## Что проверить

A. Каждый инвариант I1–I10 по коду, по трём осям: **enforcement** (где и чем держится, есть ли тест),
   **bypass** (как его обойти: поверхность Filament, не закрытая трейтом; Gate::before, возвращающий allow;
   count/export/global search/relation-счётчик до видимости; IDOR — чужой id/tenant/panel/subject в Livewire payload
   редакторов, не перепроверенный сервером; не-`#[Locked]` серверное состояние; запись мимо GrantManager/SubjectAccess/PermissionManager;
   статическое/глобальное состояние плагина; использование `@internal` классов ядра из `packages/filament/src`),
   **interfering** (смена tenant/панели ∥ сохранение, stale fingerprint, две Filament-панели).
B. Каждую строку карты приёмки (P7.1–P7.7): тест существует, проверяет заявленное (а не соседнее), не skipped/не todo.
C. Межпунктовые швы: расхождение ключей генератора (`azguard:filament:generate`) и `FilamentKeys`/`AzGuardPlugin::keys()`;
   вторые реализации данных панелей (`PanelOverview` vs команда) и генераторов; трейт-ресурсы пакета (RoleResource и др.),
   сами закрытые трейтами; права самих редакторов (не дают global grant/superadmin/wildcard/другой tenant).
D. Честность CRM README: статусы R-кейсов P7 соответствуют существующим тестам.
E. Явно отмеченные кандидаты исполнителями: `resetTable()` в `ListPermissions` (P7.3) сбрасывает фильтры; inline editable columns;
   `submitted()` в ListGrants, возвращающий сырые ключи payload; bulk по сырым ключам выбора.

Подсказка: `rg 'Gate::before|configureUsing|Storage\\Models|DirectoryResolver|PanelRecipe|DB::table|static \$' packages/filament/src`.

Тесты можно запускать точечно, например
`php -d memory_limit=1G vendor/bin/pest tests/Feature/Filament --compact` или отдельный файл/`--filter`.
Полный `composer test`, `bin/consumer-fixture.sh`, pint/phpstan запускать НЕ нужно — их прогоняет исполнитель параллельно.
Если тест не может записать файл (запрет записи) — это ограничение среды, не находка; скажи об этом.

## Требования к находкам

- Каждая находка — с **воспроизведением**: команда и её вывод, либо тест-пробник в /tmp (путь, команда запуска, вывод).
  Без воспроизведения ставь `confidence: "plausible"`.
- severity: `blocker` | `critical` | `major` | `minor` | `info`. Security-обход авторизации/IDOR — не ниже `major`.
- Укажи `file:line`, owning item `P7.n` (или пункт ядра), инвариант/строку приёмки, сценарий отказа, предлагаемое исправление.
- Не выдумывай: если не проверил — так и напиши.

## Формат итогового ответа

Последним сообщением выведи один JSON-блок (```json … ```):

```json
{
  "verdict": "GREEN | ATTENTION | RED",
  "summary": "2–5 предложений",
  "invariants": [{"id": "I1", "status": "held | violated | partial | unverified", "enforcement": "...", "bypass": "...", "interfering": "...", "evidence": "file:line / test"}],
  "acceptance": [{"item": "P7.2", "row": "V102", "tests": ["path::test name"], "status": "covered | weak | missing | skipped"}],
  "findings": [{"id": "F1", "severity": "major", "confidence": "confirmed | plausible", "location": "path:line", "owning_item": "P7.4", "invariant": "I5", "failure_scenario": "...", "basis": "...", "reproduction": "команда + вывод или путь пробника", "fix": "..."}],
  "not_checked": ["что не успел/не смог проверить"]
}
```

verdict GREEN — только если нет находок severity ≥ major и все инварианты held.

# Независимое read-only ревью фазы P7 (AzGuard 1.0, Filament) — PLAN2 P7.8, повторное после исправления находок F1–F3, F6

Ты — независимый ревьюер. Репозиторий: `/home/vostrikov/projects/packages/azguard` (ветка main, HEAD = коммит после исправлений P7.2/P7.3/P7.4).
Режим строго read-only: не изменяй файлы репозитория (ядро ОС запрещает запись вне /tmp, ~/.grok и vendor/).
Можно читать всё, запускать команды и тесты. Свои временные тесты/скрипты клади только в /tmp.
Исправления не делаешь: находишь, воспроизводишь и останавливаешься.

## Объект ревью

Вся фаза P7 «Filament по схеме панели»: коммиты от базы `1b71f18370b48c90fa7817b25fac6e99bf4863da` до HEAD
(`git log --oneline 1b71f18..HEAD`, `git diff 1b71f18..HEAD --stat`). Пункты:
- P7.1 плагин `AzGuardPlugin`, конфиг, ключи `FilamentKeys`, `FilamentSource`, `FilamentTenantResolver`, admission EnterPanel;
- P7.2 `FilamentGate`, трейты `Concerns\Authorizes{Resource,RelationManager,Page,Widget}`, bulk, экспорт (`Exports\*`);
- P7.3 ядро `Directories\PanelDirectories` + `PanelAccess::directories()`, `Editors\TargetSelector`, `RoleResource`, `PermissionResource`;
- P7.4 ядро `GrantFilter` (expiresBefore/grantedBy), `RoleGrantResource`, `PermissionGrantResource`, `Editors\ListGrants`, `Editors\GrantEditor`, `Actions\ExplainAction`, `Forms\SchemaFields`;
- P7.5 `Contracts\FilamentFormExtension`, свои поля из схемы в формах выдач;
- P7.6 ядро `Diagnostics\PanelOverview`, `Pages\PanelsPage`, `Pages\DoctorPage`;
- P7.7 `azguard:filament:generate`, `Attributes\ForFilament`, `Diagnostics\FilamentDefinitionsCheck`, расширение `azguard:make:permission` (`--authority`, `--case`).

Код: `packages/filament/src/**`, изменения ядра в `packages/core/src/**`, тесты `tests/Feature/Filament/**`,
`tests/Acceptance/Crm/FilamentTest.php`, `tests/Arch/**`, фикстуры `tests/Fixtures/Filament/**`, `tests/Fixtures/Crm/Filament/**`.

## Повторное ревью: что изменилось с первого прохода

Первое ревью (раздел `P7-verdict` в `plans/2026.10.01-№1-AZGUARD-V1/knowledge/findings-P7-review.json`) вернуло RED: 2 major, 2 minor, 2 info.
Исправления: коммиты `31ff9d2` (P7.2), `246f8ca` (P7.3) и `8594260` и `d49d0c4` (P7.4); HEAD = `d49d0c4` (`git log --oneline d54183d..HEAD`).

1. **F1 (major, I2)** — своё `Action`/`BulkAction` (не наследники Delete/ForceDelete/Restore), `DetachAction`, `DissociateAction`,
   `DetachBulkAction`, `DissociateBulkAction` на guarded-ресурсе исполнялись без права. Исправление: `packages/filament/src/Authorization/ExplicitAuthorization.php`
   (`action`, `bulkAction`), подключено в `AzGuardPlugin::authorizeRecordsOfActions()`; тесты `tests/Feature/Filament/Gate/ExplicitSurfacesTest.php`.
2. **F2 (major, I6)** — inline editable column (`Filament\Tables\Columns\Contracts\Editable`) сохраняла запись без `update`. Исправление: `ExplicitAuthorization::column`
   (отключена, пока нет `{slug}.update` на запись строки); docblock `Concerns\AuthorizesResource`, CHANGELOG.
3. **F3 (minor, I5)** — `ListPermissions::write()` вызывал `resetTable()` и сбрасывал фильтр цели; теперь нет (тест `PermissionResourceTest` «keeps the chosen panel and tenant»).
4. **F6 (minor)** — `tests/Acceptance/Crm/README.md`: R25 и R34 без `future: P7.4`; тест R25 в `tests/Feature/Filament/Grants/RoleGrantResourceTest.php`.
5. F4, F5 (info) — по замыслу, не менялись.

Твоя задача: (а) **независимо проверь, что F1/F2/F3/F6 действительно закрыты**, пробниками с обратным ожиданием (в /tmp), и что исправление
не открыло новый обход (подумай: `visible()`/`hidden()` как «явное решение», `authorize()` как перекрытие умолчания, действие без обработчика, действия
в модалке, `ActionGroup`, действия на страницах, не являющихся страницами ресурса или relation manager, колонки `SelectColumn`/`CheckboxColumn`/`ToggleColumn`,
`disabled(false)`, форма `CreateRecord`/`EditRecord`, не сломаны ли встроенные действия); (б) заново проведи полное ревью фазы по разделам A–E ниже.

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
E. Кандидаты прошлого прохода: `submitted()` в ListGrants, возвращающий сырые ключи payload; bulk по сырым ключам выбора (оба — info по замыслу);
   новые: поведение `ExplicitAuthorization` на нестандартных поверхностях Filament, перечисленных выше.

Подсказка: `rg 'Gate::before|configureUsing|Storage\\Models|DirectoryResolver|PanelRecipe|DB::table|static \$' packages/filament/src`.

Тесты можно запускать точечно, например
`php -d memory_limit=1G vendor/bin/pest tests/Feature/Filament --compact` или отдельный файл/`--filter`.
Полный `composer test`, `bin/consumer-fixture.sh`, pint/phpstan запускать НЕ нужно — их прогоняет исполнитель параллельно.
Прежние находки F1–F6 (кроме info F4/F5) не переписывай: оцени, закрыты ли они; новая находка получает следующий свободный номер (F7, F8, …).
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

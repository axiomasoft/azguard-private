---
id: D17
date: 2026-10-05
status: accepted
item: P4.5
items: [P4.5, P0.4, P0.5, P1.3]
supersedes: []
superseded_by: null
---
# D17 — Приёмка открытого P4.5 без нового review и узкая переквалификация P0.4/P0.5

**Actor:** owner, 2026-10-05. Исполнитель этой записи — Grok 4.7/high, session `01a10b61-e5d0-78e2-ae04-01a29909c20e`. Это не сессия запуска `01a10a9f-1b3e-74b0-b923-bb9153be99d1`.
**Evidence:** указание владельца в этой сессии; run `32f91b41b42a3e9843b66592224cc34008c701eff377463f27aa698d584c6926`; request `artifacts/P4.5/owner-review/request.json`; GREEN attempt-0003 кандидата `cb2f1817632003deb09238ef9a32f3c217ed1101e6cdf1b231f0e6a52e78afe4`; frozen Validation P0.4/P0.5; rector dry-run run-002.

## Solution

1. **Нового независимого review для остатка этого открытого P4.5 нет.** Не запускать Grok review и не повторять P1 review. D16 §3 сохраняется для ещё не закрытых пунктов P4. Это решение сужает только уже открытый run P4.5: дополнительный `--reviewer` и маркер `[REVIEW-EXTRA-ROUND:…]` не используются. Прежний GREEN относится к кандидату `cb2f1817…` и не является review продукта после правок Grant, генератора manifest и `Decision`. Его нельзя подставлять вместо свежего verdict. Приёмка этого закрытия — девять команд Validation P4.5, freshness и observations замыкания зависимостей. Runtime close остаётся единственным writer terminal row.

2. **P0.4, прежний критерий 4.** `git grep -nE 'axioma-studio/azguard|azguard-context' -- ':!legacy' ':!audits' ':!plans' ':!CHANGELOG.md'` допускает только явное историческое упоминание в коротком status-блоке README или в RELEASING. Фактические попадания шире: README/README.ru вне status, `.github/CONTRIBUTING.md`, SECURITY.md, UPGRADING.md, `docs/**`, и историческая строка `tests/Regression/specs/P06b.md`. README body отложен до P8.2, docs-site вне текущего scope. **Новый критерий этой переквалификации:** эти документы не переписываются; попадания фиксируются как отложенная документация до своего пункта. Квалификация проверяет, что старые имена не стали именем composer-пакета и не грузятся как код. Историческая terminal row P0.4 не переписывается и задним числом не становится GREEN.

3. **P0.5, прежние критерии 8 и 9.** 8) `git grep -nE 'AzGuard\\Context|packages/context'` по тем же исключениям обязан быть пуст. 9) `php -r` автозагрузка не находит `AzGuard\Panels\Panel`. Оба условия устарели: Panel намеренно есть в 1.0 (`class_exists` сейчас true), а `AzGuard\Context\…` живёт в отложенных docs. **Новый критерий:** legacy не автозагружается. Файлы `legacy/0.3/packages/context/src/ContextGuard.php` и `ContextNotSetException.php` есть на диске, а `class_exists('AzGuard\\Context\\ContextGuard')` и `class_exists('AzGuard\\Context\\ContextNotSetException')` после `vendor/autoload.php` равны false. Отложенные docs не правятся. Историческая terminal row P0.5 не переписывается.

4. **P0.5, прежний критерий 6.** `vendor/bin/rector process --dry-run` без изменений. На кандидате до этой правки dry-run завершился exit 2. Текущий объём P4.5: эквивалентные FlipType в `RelationSource` и `RelationScopeQuery` применены; `ThrowWithPreviousExceptionRector` пропущен только для `RelationBinding.php` и `RelationSource.php`, потому что копирование `getCode()` меняет int-code `DefinitionException`. Остальные файлы не autofix и не скрыты глобальным skip. Их владельцы — первый item, в чьих Files назван класс: Grant/RoleContribution P1.3; IdentityCodec P1.1; WriteGuardedBuilder/GuardsDirectWrites P3.4; Storage P3.2; PolicyDecider/RuntimeInvoker/AccessPipeline/AuthorityStage/PrepareStage/Trace P4.1; BaseRole P1.6; Authorizer P4.7; AzGuardManager P2.6; PanelCatalog P2.5; AzGuardConfig/Panel/PanelBuilder/PanelCompiler/PanelFingerprint/PanelRegistry P2.1; BaseAssignmentScope P2.9; DatabaseSource P4.17; FolderSource/PanelDiscovery P2.8; PanelSources P2.5; PanelResolver P2.2. `ReadAttempt.php` и `StorageReadSession.php` ни в одном Files не названы; зарегистрированы за подсистемами P4.1 и P3.2 и здесь не правятся. Этот RED не становится историческим GREEN. Для закрытия P4.5 он не является командой Validation P4.5; отклонение от старого «без изменений» записано здесь и не лечится массовым skip.

5. **P1.3.** `Decision::allow`/`make` копирует `grants` в новый `list<Grant>` и отвергает не-list и не-Grant. Регрессия — в `DecisionTest`. Два теста `Grant::assertFields` сохраняются. Повторный review P1 не запускается.

## Why

D16 §3 уже израсходовал два completed review round на прежний кандидат. Владелец явно запретил ещё один round и повтор P1. Старые текстовые гейты P0.4/P0.5 описывают репозиторий до Panel 1.0 и до отложенной документации; чинить README, docs и чужие Rector-зоны ради P4.5 нельзя. Frozen specs закрытых пунктов не редактируются: смена байтов спецификации даёт `TERMINAL_SPEC_STALE` на их terminal row.

## Consequences

Новая квалификация P0.2–P0.5 и P1.1–P1.4, P1.6–P1.8 идёт по фактическим командам. `allow/allow` новой проверки пишется только если `task_contract.evaluate` вернул эти коды. Устаревшие критерии 4, 8, 9 и Rector-критерий 6 не объявляются GREEN. Исторические closed-green P0.4, P0.5, P1.7 и P1.8 не переписываются; им дописывается только каноническая строка уже существующего GREEN, без новой квалификации. P4.5 закрывается своим Validation через `plan-work close` того же run. Владелец 2026-10-05 приказал довести пункт до green. Спецификация не объявляет `owner-review`, третий review round запрещён, прежний GREEN остаётся другим кандидатом. Файл request снят, чтобы close записал terminal row по этой приёмке. Verdict не подделывается.

# Codex-only execution contract — 2026-07-21

## 1. Назначение

Этот документ — обязательный вход для всех незавершённых items P4/P5. Он заменяет
неявный контекст прежних Claude Code-сессий явным контрактом исполнения в ChatGPT/Codex.
После 2026-07-21 Claude Code не используется для реализации, ревью, закрытия или аудита
этого плана. Исторические упоминания Claude в закрытых items сохраняются только как
provenance и не являются инструкциями запуска.

## 2. Готовность среды

- Требуется установленный и включённый `task@swissknifeman` версии `0.3.0` или новее.
- После установки/обновления plugin обязательна новая Codex-сессия: список skills и
  команд фиксируется при старте сессии.
- Рабочий каталог: `/home/vostrikov/projects/packages/azguard`.
- Перед каждым item: прочитать `handoff.md`, этот контракт, спецификацию item и выполнить
  `git status --short` + scoped `git diff`. Чужой dirty diff не стирать и не переписывать.
- Состояние прежнего агента не считается доверенным контекстом. Принимаются только
  артефакты в репозитории, история git и заново воспроизводимые проверки.

## 3. Модели, команды и стоимость

| Работа | Команда/сессия | Модель · effort | Контроль |
|:--|:--|:--|:--|
| P4.8 | `task:plan-run … P4.8` | GPT-5.6 Terra · high | отдельный Sol/high read-only review |
| P4.7 | `task:plan-run … P4.7` | GPT-5.6 Terra · high | отдельный Sol/high read-only review |
| P4.9–P4.10 | `task:plan-exec … P4.9 P4.10` | GPT-5.6 Terra · medium | один Sol/high review итогового B6 |
| P4.3 | `task:plan-exec … P4.3` | GPT-5.6 Terra · medium | full review по item-контракту |
| P4.4 | `task:plan-exec … P4.4` | GPT-5.6 Terra · medium | отдельный Sol/high concurrency-review |
| P4.5–P4.6 | `task:plan-exec … P4.5 P4.6` | GPT-5.6 Terra · medium | full/light review по контрактам |
| Закрытие P4 | `task:plan-close … P4` | GPT-5.6 Terra · low | bookkeeping, без новых решений |
| Аудит P4 | `task:plan-audit … P4` в новой сессии | GPT-5.6 Sol · xhigh | только GREEN открывает P5 |
| P5.1 | `task:plan-run … P5.1` | GPT-5.6 Sol · high | открытый синтез канона |
| P5.2–P5.3 | `task:plan-exec … P5.2 P5.3` | GPT-5.6 Terra · medium | approve владельца до push тега |
| Закрытие/аудит P5 | `task:plan-close`, затем новая `task:plan-audit` | Terra · low, затем Sol · xhigh | GREEN до archive |
| Архив | `task:plan-close archive …` | GPT-5.6 Terra · low | только после терминальности и GREEN-аудита |

GPT-5.6 Luna/low допускается только для изолированной read-only разведки, запуска
детерминированных проверок и сжатия логов. Luna не пишет production-код, не меняет план и
не выносит финальный verdict о миграциях, concurrency, CI или релизе. Если отдельное
делегирование недоступно или дороже переключения, supporting work выполняет текущая Terra:
экономия не должна создавать дополнительную координацию.

## 4. Контур исполнения и ревью

1. Один writer за раз. Параллельная запись в общий worktree запрещена.
2. Reviewer работает read-only по конкретному diff, item-контракту и результатам тестов;
   он не исправляет найденное сам.
3. Исправления возвращаются writer-модели: Terra/high для P4.8/P4.7/P4.4, Terra/medium
   для остальных frozen-spec items.
4. Максимум два цикла `review → fix → re-review`. Третий неснятый содержательный finding
   означает эскалацию по §10 или `task:plan-design`, а не бесконечное расходование токенов.
5. Reviewer обязан проверять доказательства, а не доверять пересказу writer: scoped diff,
   тест-команды, exit codes и соответствие Files/Scope/Validation.
6. Коммит-гейт остаётся двухступенчатым: item-commit только declared Files, затем
   bookkeeping-commit плана. `git add -A/-a` запрещены.

## 5. Приёмка незавершённого P4.8

Dirty diff, оставшийся после Claude Code, — недоверенный вход, не готовое решение.
Codex должен сначала:

1. инвентаризировать каждый hunk и сопоставить его P4.8/P4.10;
2. воспроизвести исходный дефект и целевые проверки на SQLite/PostgreSQL/MySQL;
3. исключить временный `DebugPgAbortTest.php` из deliverables либо перенести нужное
   доказательство в объявленный целевой тест;
4. не включать `.github/workflows/tests.yml` в P4.8 — это ownership P4.10;
5. перед закрытием получить независимый Sol/high verdict по фактическому diff.

## 6. Cold start

Старт новой исполнительной сессии:

```bash
codex -C /home/vostrikov/projects/packages/azguard \
  -m gpt-5.6-terra \
  -c 'model_reasoning_effort="high"'
```

Первое сообщение в ней:

```text
$ task:plan-run 2026.07.18-AZGUARD-STABLE P4.8

Исполняй Codex-only контракт research/05-codex-execution-contract.md. Сначала прочитай
handoff.md, plan.md D30–D34 и полную спецификацию P4.8; затем инвентаризируй git status/diff.
Не доверяй незавершённому diff без повторяемых доказательств, не трогай P4.7/P4.9/P4.10
и не включай .github/workflows/tests.yml в item-коммит P4.8. Перед закрытием обязателен
отдельный read-only GPT-5.6 Sol/high review фактического diff.
```

Если `task:plan-run` не распознаётся в новой сессии, разработку не начинать: сначала
проверить состояние plugin/перезапустить Codex. Ручной prompt больше не считается штатным
fallback, потому что он обходит harness plan-protocol.

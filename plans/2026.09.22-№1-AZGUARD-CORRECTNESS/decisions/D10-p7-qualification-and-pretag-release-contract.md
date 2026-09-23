---
id: D10
date: 2026-09-23
status: accepted
item: P7
items: [P7, P7.1, P7.2]
supersedes: []
superseded_by: null
---
# D10 — Проверяемая матрица и changelog до тега

**Actor:** plan-designer/Codex root (Task route mapping for current selector unavailable)
**Evidence:** RAG:— `findings/verification.md` F21–F23; `composer.json`, package manifests, `tests.yml`, `mutation.yml`, Redis test, `release.yml`, `changelog.yml`, `RELEASING.md`, root `CHANGELOG.md`. CI jobs and external releases were not run in this design.

## Решение

P7.1 проверяет заявленные PHP/Laravel/Testbench/Filament combinations на реальную
Composer installability в уже существующей `tests.yml` matrix. Невозможная
комбинация получает точный evidence и корректировку declared/CI support contract;
матрицу не расширяют по числу строк ради отчёта. PostgreSQL 16/MySQL 8 lanes уже
существуют. Их новый migration/identity contract P6 подтверждается на изолированных
`*_test` DB. SQLite и array-cache tests не свидетельствуют о Redis lock/worker
поведении: отдельный обязательный Redis lane запускает cross-process и
post-commit/revoke regressions с реальным Redis и уникальным prefix; skip из-за
отсутствия extension/service не является GREEN. P1–P6 владеют исправлениями своих
продуктовых дефектов; P7.1 добавляет лишь недостающие интеграционные regressions и
сводит результаты, не дублируя их тесты или не повышая thresholds без baseline.
Существующие mutation/coverage/type gates сохраняют текущие измеренные пороги.

P7.2 делает root `CHANGELOG.md` частью candidate commit до создания тега.
Tag-triggered `changelog.yml` writer удаляется; `release.yml` проверяет наличие
раздела версии в tagged tree до публикации и остаётся read-only после тега.
`RELEASING.md` описывает candidate check, явное решение о
версии/BC, review upgrade notes, затем отдельный owner-authorized tag/push.
Release job fail closed, если version entry отсутствует в tagged commit.
Пакетные historical changelogs не переписываются; один root ledger получает
release-visible изменения. Дизайн и исполнение P7 не создают tag, GitHub Release,
split или Packagist publication.

## Почему

`tests.yml` уже проверяет SQLite и два реальных SQL engine, но Redis test может
закончиться skip без Redis extension. Корневые Composer constraints и CI support
grid должны совпадать. Сейчас `changelog.yml` реагирует на push тега и коммитит
результат в `main`, поэтому tagged commit не содержит созданный им changelog.
Предварительная запись и проверка tagged tree устраняют этот порядок событий.

## Consequences

P7.1 владеет qualification evidence, точечным CI/test gap и одним light review
матрицы/Redis/mutation seams. P7.2 владеет RU/EN/upgrade parity, candidate
release check и одним light review docs/release seams. Любой новый material
product defect возвращается owning P1–P6 item; P7 не переопределяет их контракт.

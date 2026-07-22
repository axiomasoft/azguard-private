# RAG + gh-recon: release-инфраструктура для P5.2 (2026-07-18)

> Слой 1. Факты внешнего состояния (GitHub) и RAG-верификация guard-механики split.yml.
> Потребители: plan.md D25, phases/P5.md P5.2.

## 1. Состояние release-инфраструктуры (gh CLI, 2026-07-18)

| Факт | Команда | Результат | Вердикт |
|:--|:--|:--|:--|
| Remote монорепо | `git remote -v` | `https://github.com/axiomasoft/azguard-private.git` | репо ПРИВАТНЫЙ, живёт под `axiomasoft`, не под `axioma-studio` |
| Split-репо core | `gh api repos/axioma-studio/azguard-core` | 404 Not Found | split-цели НЕ созданы (filament/context — аналогично не создавались, one-time setup RELEASING.md не выполнялся) |
| Org axioma-studio | `gh api orgs/axioma-studio` | существует (Organization) | org есть, но пустая по azguard-репо |
| Секрет split | `gh secret list -R axiomasoft/azguard-private` | пусто | `MONOREPO_SPLIT_TOKEN` НЕ настроен |

Следствие: тег `v0.3.0` штатно запустит `release.yml` (GITHUB_TOKEN, работает) и
`changelog.yml` (работает), но `split.yml` гарантированно упадёт (нет секрета, нет
целевых репо). Packagist не подключён. Вердикт владельца (вопрос 2026-07-18):
**тег + GH Release, split/Packagist отложить** → D25.

## 2. RAG: guard-механика для отложенного split (RAG:✅ 2026-07-18)

Запрос: доступность контекстов в `jobs.<job_id>.if` GitHub Actions.
Источник: [GitHub Docs — Contexts reference](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts) (WebSearch 2026-07-18).

- Контекст **`vars`** ДОСТУПЕН в `jobs.<job_id>.if` — документированный пример
  `if: ${{ vars.USE_VARIABLES == 'true' }}` на уровне job. ✅
- Контекст **`secrets`** в `jobs.<job_id>.if` НЕ доступен (доступен только на уровне
  steps/env) — guard через проверку секрета на job-уровне невозможен. ✅

Вердикт: guard отложенного split — job-level `if: ${{ vars.SPLIT_ENABLED == 'true' }}`
по repository variable; переменная не создаётся (дефолт = job скипается). Включение
split после one-time setup = создать переменную `SPLIT_ENABLED=true`, код не трогается.

## 3. Факты манифестов (repo-grounded)

- `packages/filament/composer.json`, `packages/context/composer.json`:
  `axioma-studio/azguard-core: ^0.2` — перед тегом v0.3.0 требуется бамп до `^0.3`
  (после breaking P1/P2 констрейнт `^0.2` ложен).
- Root `composer.json` path-repository `options.versions`: все три пакета `0.2.0` —
  правило RELEASING.md «keep in sync» требует `0.3.0`.
- `release.yml` гейтит только wildcard `"*"` в констрейнтах сателлитов — `^0.2` он
  НЕ поймает; бамп — обязанность P5.2, не CI.
- Пакеты namеренно без `version`-поля (версию даёт тег) — поле НЕ добавлять.

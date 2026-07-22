# Recon: структура планов vaulter как шаблон (2026-07-18)

> Слой 1. Отчёт Explore-субагента по `/home/vostrikov/projects/packages/vaulter`
> (образец владельца для плана azguard). Вердикт: repo-grounded (чужой репо, прочитан).

## 1. Цепочка планов vaulter (хронология)

| # | План | Ось / назначение | Статус |
|:--|:--|:--|:--|
| 1 | AUDIT-VLT | Сплошной read-only аудит по 4 осям (безопасность, встраиваемость/DX, гибкость/расширяемость, качество/производительность). Код НЕ чинит. Выход — реестр находок + бэклог. 6 фаз, 30 items | архив |
| 2 | VLT-REMED | Ремедиация 103 находок (3 Blocker · 37 Major · 58 Minor · 5 Nit) + гармонизация доменной структуры; финал — adversarial review | архив, 🟠 |
| 3 | VLT-HSEAM | Editor-agnostic шов — поглощён VLT-CANON (⛔ Superseded) | архив |
| 4 | VLT-CANON | Подключаемые движки + финальный структурный канон монорепо (пре-1.0 переименования, разбор catch-all-папок), ADR на решение | архив, 🟠 |
| 5 | VLT-REL | Release-готовность: cut-line публичной поверхности → заморозка arch/snapshot-гейтом → SemVer-политика (0.x) → первый тег v0.2.0 | архив, 🟢 |
| 6 | VLT-LOAD | Нагрузочная готовность: SLO → k6-харнесс → профилирование → память → конкуренция → очереди → деградация → наблюдаемость | активен 🟡 |
| 7 | VLT-MUT | Мутационный гейт: честный замер → ratchet → Score ≥95 по пакетам | активен 🟡 |

**Логика цепочки:** аудит (read-only) → ремедиация → структурный канон → релиз
(заморозка API + тег) → углубление тестирования двумя ортогональными осями
(нагрузка ⊥ корректность/мутация). VLT-LOAD-фиксы горячих путей не открываются,
пока VLT-MUT не довёл пакет до Score ≥95 (D11): «нельзя переписывать поведение под
тестами, которые не ловят инверсию условия».

## 2. Лестница тестового углубления (ступени инфраструктуры)

- **0** — юнит/feature-база: sqlite :memory:, детерминированный APP_KEY, Makefile
  test/quality/ci, per-пакетные workflow.
- **1** — двухдрайверная БД-матрица: на SQLite самоскипается PG-only код → зеркальные
  лейны Postgres 16 (docker testbench.pgsql.yaml) + правило «union двух прогонов».
- **2** — e2e-стенд поверх `testbench serve` (Playwright, headless-smoke, свой
  docker-compose стек: postgres + laravel + hub + vite).
- **3** — docker-воспроизводимость: Dockerfile'ы, image-workflow; «железный конверт»
  на cpuset-пиннинге (не --cpus-квоте).
- **4** — load (`load/`): design-first — SLO как контракт (slo.md, slo-gates.md:
  двухклассовый гейт A блокирующий/B advisory, reference-env тиры S/M/L), стоковый k6
  (+сценарии, Prometheus-out), seed «объёмных и грязных» состояний, двухплоскостной
  гейт (k6 thresholds + серверные PromQL-ассерты), baseline-артефакты, CI load.yml
  (smoke-perf/regression/baseline-guard).
- **5** — mutation: нативный Pest 4 `--mutate --covered-only` (Infection выброшен как
  несовместимый — vaulter D38/P5.9), дисциплина: холодный кэш, снятый ложный
  `--parallel`, ratchet-порог `--min = floor(Score) − 5` только вверх, цель ≥95.

## 3. RUNBOOK.md

`plans/RUNBOOK.md` — durable-оркестратор цепочки НЕСКОЛЬКИХ планов: чек-лист из 24
шагов, каждый — готовая команда для новой сессии с преднастроенными Model/Thinking;
дисциплина «шаг → [x] → читай handoff → выведи следующий launch-block»; приоритет
истины: handoff.md конкретного плана > RUNBOOK. Роль: сшивает мастер-планы в одну
исполнимую последовательность сессий.

## 4. Переиспользуемый шаблон дорожки (суть для azguard)

1. **Аудит read-only** — 4 оси, закрытые чеклисты C1..Cn с pass/fail/partial/n/a +
   file:line, выход: REGISTER.md находок + ранжированный бэклог. Код не трогается.
2. **Ремедиация** — партиция находок на батчи по корню, волны W0→W4 (Blocker первыми),
   финал — adversarial review.
3. **Структурный канон** — пре-1.0 переименования (бесплатны по SemVer), изоляция швов
   за контрактами, ADR на каждое решение.
4. **Release-готовность** — cut-line публичной поверхности → заморозка
   arch-test/snapshot-гейтом → SemVer-политика → каталог известных ограничений → тег.
5. **Углубление тестирования** — параллельные ортогональные оси, каждая отдельным
   планом (нагрузка; мутация/корректность), с дисциплиной зависимости между ними.
6. Опционально — RUNBOOK-оркестратор поверх цепочки.

## 5. Якоря (vaulter)

plans/{ACTIVE.md,RUNBOOK.md} · plans/AUDIT-VLT/{plan.md,phases/,findings/REGISTER.md,
artifacts/finding-template.md} · plans/archive/{VLT-REMED,VLT-CANON,VLT-REL}/plan.md ·
plans/VLT-LOAD/{plan.md,slo.md,slo-gates.md,reference-env.md} · plans/VLT-MUT/plan.md ·
phpunit.xml · testbench.yaml · Makefile · e2e/ · hocuspocus-server/ · load/ ·
.github/workflows/{load,mutation,tests,static-analysis,monorepo-split}.yml

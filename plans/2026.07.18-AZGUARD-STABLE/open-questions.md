# Open Questions — 2026.07.18-AZGUARD-STABLE

| Q# | Вопрос | Статус | Где решается |
|:--|:--|:--|:--|
| Q1 | Финальная версия тега: 0.3.0 (ориентир брифа) или 0.9.x/1.0-rc после заморозки поверхности? | Resolved → D22 (владелец: 0.3.0, 2026-07-18) | Обсуждение §2 plan.md; тег P5.2 |
| Q2 | Состав docker-матрицы P4 + портируемость СУБД | Resolved → D23 + recon (findings/recon-db-portability-2026-07-18.md): PG-only фич нет, пакет portable; матрица = SQLite+Postgres+MySQL8 (MariaDB опц.), Redis опционален (тест на database cache-драйвере); риск — collation RBAC-ключей MySQL → P4-item hardening. Финальный состав — детализация P4 | Обсуждение §3 plan.md; findings/recon-db-portability-2026-07-18.md; детализация P4 |
| Q3 | Конфликт ТЗ P1.1: default-panel fallback (D10-б) vs fail-closed/D5 — `resolveDefault(null)` никогда не null → ветка D5 недостижима, изоляция ослабевает, падает anti-regression A1 | Resolved → D27 (владелец: «убрать fallback», 2026-07-18): fallback упразднён, P1.1 = только снять console-bypass; null-семантика → C-02/P1.2 | plan.md §5 D27; phases/P1.md P1.1/C-02 |

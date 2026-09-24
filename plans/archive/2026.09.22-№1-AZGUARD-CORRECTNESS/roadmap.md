# Roadmap исполнения — 2026.09.22-№1-AZGUARD-CORRECTNESS

<!-- execution-sheet/v1 -->

**Обновлён:** 2026-09-23 · **Соответствует:** `plan.md` Layout v2, D4 и D12.

Эта карта группирует уже детализированные items. Design finish завершён;
исполнение откроет только общий
`task:plan-audit 2026.09.22-№1-AZGUARD-CORRECTNESS design` с GREEN verdict.
Routing остаётся контрактом, а execution sheet ниже — единственный
источник launch-команд. Handoff только выбирает текущую ready строку.

## Правило группировки

Рекомендуемые B2–B7 сохраняют общий producer/consumer, Files и validation context
внутри фазы. Готовое подмножество допускается при выполненных input gates.
P1.1 и P1.2 остаются `solo`: P1.1 создаёт identity contract, P1.2 потребляет
его terminal evidence и отдельно проверяет deadline/envelope. Межфазный batch не создаётся.

На finish группы пересмотрены по всему graph. У всех членов один Exec;
единый launch route — максимум класса/effort своих items (`implementation/medium`
или `frontier/high`), review — максимум строк
batch. Provider-specific model не хранится в плане и выбирается adapter для всей группы.
Каждый item сохраняет собственную валидацию, result и close внутри группы.

## Execution sheet — exact admissions

| Command | Unified route | Review |
|:--|:--|:--|
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P1.1` | `implementation/medium` | `none` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P1.2` | `frontier/high` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P2.1 P2.2 P2.3` | `frontier/high` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P3.1 P3.2` | `implementation/medium` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P4.1 P4.2` | `frontier/high` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P5.1 P5.2` | `implementation/medium` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P6.1 P6.2` | `frontier/high` | `light` |
| `task:plan-exec 2026.09.22-№1-AZGUARD-CORRECTNESS P7.1 P7.2` | `implementation/medium` | `light` |

## Карта зависимостей после GREEN design audit

| Items | Batch | Input gate |
|:--|:--|:--|
| P1.1 | solo | общий design audit GREEN |
| P1.2 | solo | P1.1 GREEN |
| P2.1–P2.3 | B2 | P1.2 GREEN; затем локальные item dependencies |
| P3.1–P3.2 | B3 | P2.3 GREEN; затем локальные item dependencies |
| P4.1–P4.2 | B4 | P1.2 GREEN; затем локальные item dependencies |
| P5.1–P5.2 | B5 | P3.2/P4.2 GREEN; затем локальные item dependencies |
| P6.1–P6.2 | B6 | P3.2/P5.2 GREEN; затем локальные item dependencies |
| P7.1–P7.2 | B7 | P2.3/P3.2/P4.2/P5.2/P6.2 GREEN; P7.2 после P7.1 |

## Гейты

| Где | Условие | Блокирует |
|:--|:--|:--|
| D4 | один общий GREEN design audit | все plan-exec/plan-run |
| P1–P6 | terminal predecessor evidence согласно `plan.md` и item Inputs | downstream items |
| P7.1 | isolated `*_test` SQL targets; real Redis service/extension без skip | qualification GREEN и P7.2 |
| P7.2 | changelog в candidate commit; read-only pre-tag check | готовность к отдельному owner-approved release |

Публикация релиза, tag/push, split и Packagist не входят в исполнение этого плана.

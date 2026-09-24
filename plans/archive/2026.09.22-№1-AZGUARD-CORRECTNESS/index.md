# Карта плана

| Носитель | Назначение / потребитель |
|:--|:--|
| `plan.md` | Основные инварианты, Routing, зависимости P1–P7 |
| `brief/00-brief.md` | Вход владельца, scope и ограничения Task/environment |
| `findings/verification.md` | Проверка аудита и F1–F23; ссылки на owning фазы |
| `research/Pn-design.md` | Полное implementation dossier: связи кода, алгоритмы, failure modes, validation |
| `research/Pn-design-rag.md` | Проверенная выжимка external/repository research и граница доверия |
| `artifacts/Pn-design/examples.md` | Конкретные execution-ready примеры и interleavings |
| `artifacts/Pn-design-rag/` | Raw retrieval capture или явная запись, почему search не нужен |
| `decisions/D1-scope-and-routing.md` | Граница основного design и последующие sol phases |
| `decisions/D2-correctness-and-compatibility.md` | Correctness-first и compatibility policy |
| `open-questions.md` | Generated view; все прежние Q-развилки разрешены в D3–D12 |
| `phases/Pn/Pn.md` | Детализированный phase contract и acceptance |
| `phases/Pn/Pn.m.md` | Детализированный item contract |
| `roadmap.md` | Validated `execution-sheet/v1`: единый источник batch launch-команд после audit |
| `status.md`, `*.state.md` | Generated status; не редактировать вручную |
| `journal.jsonl` | Append-only authoring history |
| `handoff.md` | Единственный semantic Next: plan-wide design audit |

Внешние источники — `docs/reference.md`; deferred идеи — `roadmap/azguard-evolution.md`.
F1–F3 → P1; F4–F8 → P2; F9–F10 → P3; F11–F15 → P4;
F16–F17 → P5; F18–F20 → P6; F21–F23 → P7 с already-present пометками.
Typed continuity относится к завершению основного слоя, не к completion фаз.

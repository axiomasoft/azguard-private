# Метрики (выбор зависит от задачи)

Справочник к скиллу `eval-design`.


### Generative tasks (генерация текста, ответ ученику, mem caption)
- **Exact match** — для строго детерминированных частей (числа, даты)
- **Embedding similarity** — семантическая близость к reference (cosine, threshold ~0.85)
- **LLM-as-judge** — отдельная модель оценивает ответ по rubric (0–5 шкала)
- **Human eval** — для критичных доменов (педагогика, финсоветы)

### Classification / extraction (тегирование, извлечение полей)
- **Precision / Recall / F1** — стандартно
- **Per-class confusion matrix** — где модель путается

### Tool-use / agentic (Finbrain MCP, Edufy tutor с tools)
- **Tool selection accuracy** — выбрал ли нужный tool
- **Argument correctness** — правильные ли аргументы
- **Recovery rate** — справляется ли с ошибкой tool'а
- **Step efficiency** — сколько шагов на задачу (меньше = лучше)

### Safety / refusal
- **Jailbreak resistance** — % отказа на adversarial промпты
- **False refusal** — % отказа на легитимных запросах (не должно быть слишком высоким)
- **PII leak rate** — утечка персональных данных в выводе

### Cost / latency
- **Tokens per task** — для cost tracking
- **p50/p95 latency** — UX

---

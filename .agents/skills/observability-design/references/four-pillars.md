# 4 столпа observability

Справочник к скиллу `observability-design`.


### 1. Logs
**Что:** структурированные события с контекстом.
**Решения:**
- Уровни: ERROR / WARN / INFO / DEBUG — когда что писать
- Формат: JSON со схемой (timestamp, level, service, trace_id, user_id, message, attrs)
- Где: stdout → агрегатор (Loki / CloudWatch / Datadog / Better Stack)
- Retention: hot (7d) / warm (30d) / cold (1y по compliance)
- PII в логах: **запретить** или **обязательная маскировка** (см. `security-design`)

### 2. Metrics
**Что:** агрегированные числа во времени.
**Категории:**
- **RED** (для request-driven): Rate, Errors, Duration — на каждый эндпоинт
- **USE** (для resources): Utilization, Saturation, Errors — на CPU/RAM/disk/network/DB-pool
- **Business KPI**: MRR, активные пользователи, конверсия — отдельный dashboard
- **Custom domain**: для AI-проектов — token usage, latency per model, hallucination rate (см. `eval-design`)

### 3. Traces
**Что:** путь одного запроса через все компоненты.
**Решения:**
- Стандарт: OpenTelemetry (по умолчанию)
- Sampling: head-based (1–10%) или tail-based (100% ошибок, 1% успехов)
- Что трейсить обязательно: входящие HTTP, исходящие HTTP, БД-запросы, queue messages, LLM-вызовы
- Trace_id propagation: через все service-границы (включая background jobs)

### 4. Alerts
**Что:** автоматические сигналы о деградации.
**Принципы:**
- Алерт = action required. Если нет действия — не алерт, это дашборд.
- Severity tiers: P1 (paging, 24/7) / P2 (рабочее время) / P3 (тикет)
- Каждому алерту — runbook (см. ниже)
- Запретить flaky alerts: после 3-го ложного срабатывания — переделать или удалить

---

<!-- Generated Codex runtime projection; do not edit as source.
Canonical source: packages/task/commands/research.md
Canonical SHA-256: sha256:18a3496b60197e30100f8e8910e241983d4f2c989a98806023ca0fb05071ea23
Adapter: task.codex-command/1.0.3
-->
Это задача ИССЛЕДОВАНИЯ. Ввод пользователя: «$ARGUMENTS».
Модель/effort запинены (frontier/high) — не переключай вручную.

Профиль `research`:
1. **Формулируй запросы по дисциплине `query-craft`** (никогда не голый «как сделать X» без версии+контекста);
   выбор движка — `mcp-advisor` (context7 для доков библиотек → Perplexity → WebSearch). Тяжёлый запрос —
   дроби на узкие.
2. **Fan-out оправдан** (breadth-first): независимые искатели (implementation) по разным граням + adversarial-verify;
   синтез (frontier) с явными цитатами/источниками. Reduce-фаза отделена от discovery.
   Оркестрация мультиагентным `*.js`-скриптом → сначала скилл `general/workflow-craft` (матчинг 4
   канонических шаблонов + обязательные стадии design vs dev) и конструктор
   `packages/task/workflows/README.md`, не изобретай оркестрацию с нуля.
3. **Внешние факты верифицируй до фиксации** (`verify-claims`); не утверждай по памяти.
4. Значимые источники — в провенанс (`docs/reference.md`, если есть).

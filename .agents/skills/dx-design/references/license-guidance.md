# License

Справочник к скиллу `dx-design`.

MIT (или другая, см. oss-governance)
```

**Что НЕ должно быть в README:**
- Длинная история «как мы пришли к идее»
- Сравнения с конкурентами (это в отдельный `COMPARISON.md`)
- Roadmap внутри README (это в `ROADMAP.md`)
- Полный API reference (это в docs)

### 3. API ergonomics
**Правила:**

| Правило | Пример good | Пример bad |
|:---|:---|:---|
| Один основной entry-point | `import { Brain } from 'brainkit'` | `import { BrainCore, BrainConfig, brainSetup } from 'brainkit/internal/...'` |
| Sensible defaults | `new Brain()` работает | `new Brain({ config: required })` |
| Builder pattern для сложного | `Brain.builder().withCache().build()` | `new Brain(c, t, e, x, t)` |
| Async-first, не sync обёртки | `await b.query()` | `b.queryAsync()` (имя выдаёт sync-наследие) |
| Error throws, не return-null | `throw new BrainError(...)` | `return null /* проверь сам */` |
| Type-safe (не `any`) | `Brain<TResponse>` | `query(): Promise<any>` |

**Тест:** новый пользователь должен написать первую полезную интеграцию **без чтения API reference**. Только README + IntelliSense.

### 4. Error messages
**Структура хорошего error:**

```
[ProjectName] {Что произошло.}
  Why: {Почему — короткое объяснение причины.}
  Fix: {Что сделать пользователю.}
  Docs: {link на raison-d'être этой ошибки или troubleshooting.}
```

Пример good:
```
[Brain] Cannot connect to model endpoint.
  Why: BRAIN_API_KEY env var is missing.
  Fix: export BRAIN_API_KEY=sk-... and retry.
  Docs: https://brainkit.dev/docs/auth#api-key
```

Пример bad: `Error: ECONNREFUSED 127.0.0.1:443`

**Правило:** каждое уникальное error должно иметь URL в документации (даже если это якорь в одном troubleshooting-документе).

### 5. CLI UX (если применимо)

| Правило | Пример |
|:---|:---|
| `<name> --help` показывает useful summary | не только список флагов |
| `<name>` (без аргументов) НЕ падает | показывает hint или launches REPL |
| Цвета только в TTY (NO_COLOR / not piped) | автодетект |
| Прогресс-бар для > 2 сек операций | `███░░░ 42%` |
| `--json` flag для машинного вывода | для скриптов |
| Exit code 0 = ok, 1 = user error, 2 = bug | стандарт |

### 6. Документация (структура)

Минимум для `v1.0+`:

```
docs/
├── README.md           # → ссылается на разделы ниже
├── getting-started.md  # 5-минутный туториал
├── core-concepts.md    # ментальная модель пакета
├── api/                # сгенерированный API reference (TypeDoc / phpDocumentor / dartdoc)
├── guides/             # рецепты под use cases
│   ├── caching.md
│   ├── streaming.md
│   └── ...
├── troubleshooting.md  # частые проблемы (синхронизируется с error messages)
└── migration/          # для каждого MAJOR — migration guide
    ├── 0-to-1.md
    └── 1-to-2.md
```

Документация **рядом с кодом** (в репо), не на отдельном сайте, который умирает.

---

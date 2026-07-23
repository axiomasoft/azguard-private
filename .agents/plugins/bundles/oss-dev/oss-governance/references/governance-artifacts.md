# 6 артефактов governance (по приоритету)

Справочник к скиллу `oss-governance`.


### 1. LICENSE (обязательно перед публикацией)

| Лицензия | Когда выбирать | Плюсы | Минусы |
|:---|:---|:---|:---|
| **MIT** | Дефолт для библиотек | Максимальная adopt-rate, простая | Нет copyleft, не защищает от форков-в-проприетарь |
| **Apache-2.0** | Если важна patent-grant | Явный patent grant, защита от submarine patents | Чуть сложнее, требует NOTICE |
| **BSD-3-Clause** | Аналог MIT с no-endorsement clause | Простая, BSD-семья | Аналогично MIT по adopt |
| **MPL-2.0** | Если хочется file-level copyleft | Можно линковать в проприетарь, но изменения в файлах MPL остаются открытыми | Меньше известна |
| **AGPL-3.0** | SaaS-сервис, который ты сам хостишь | Защищает от competitive hosting | Многие компании запрещают AGPL в продукте — резко снижает adopt |
| **GPL-3.0** | Полностью copyleft library | Сильная защита открытости | Заразность — конец adoption в commercial |
| **BUSL-1.1 / SSPL** | source-available, не OSS | Защита бизнес-модели | **Это не OSS** — нельзя называть OSI-compliant |

**Дефолт для vault-проектов:** MIT. **Исключения:**
- Если есть патенты или сильный риск patent troll → Apache-2.0
- Если SaaS-конкуренция (типа Elastic) → подумать про AGPL или BUSL, но это уже не OSS

**Внутри файла LICENSE — текст лицензии полностью**, не ссылка. SPDX-идентификатор в `package.json`/`composer.json` обязателен.

### 2. CODE_OF_CONDUCT.md
Стандарт — **Contributor Covenant v2.1** (https://www.contributor-covenant.org). Не сочинять свой.

Что добавить от себя:
- Email для сообщений о нарушениях (отдельный, не личный)
- Reporting flow (анонимность, конфиденциальность)
- Enforcement ladder (warning → temp ban → permanent ban)

### 3. CONTRIBUTING.md
**Структура:**

```markdown
# Contributing to [ProjectName]

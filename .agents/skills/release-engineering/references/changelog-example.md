# [1.1.0] - 2025-12-10

Справочник к скиллу `release-engineering`.

...
```

**Автоматизация** — рекомендую один из:
- `changesets` (JS): PR-driven, контрибьютор пишет changeset в PR
- `release-please` (Google, поддерживает много языков): commit-conventional, автогенерация
- Ручное — для совсем маленьких пакетов

Не использовать «дамп git log» — это не changelog.

### 4. Release pipeline (CI/CD)
Минимум для `v1.0+`:

```yaml
# .github/workflows/release.yml (схема, не финал)
on:
  push:
    tags: ['v*']

jobs:
  test:
    # полный test matrix (все версии runtime + OS)
  build:
    needs: test
    # сборка артефактов
  publish:
    needs: build
    # publish на npm / Packagist / pub.dev
    # требует secret (NPM_TOKEN и т.п.) через OIDC если возможно
  sign:
    # GPG-подпись релиза в GitHub Releases
  changelog:
    # секция [Unreleased] → новая версия в CHANGELOG.md
```

**Правила:**
- Релиз только из тэгов, не из main-веток (предотвращает accidental publish)
- Provenance (npm `--provenance`, GitHub OIDC) — обязательно для `v1.0+`
- 2FA на публикацию пакета — обязательно для всех мейнтейнеров

### 5. Deprecation policy
Когда что-то надо удалить — **не удалять сразу**. Стандартный flow:

| Шаг | Когда | Действие |
|:---|:---|:---|
| 1. Mark deprecated | MINOR | JSDoc `@deprecated`, console warn при использовании |
| 2. Wait | минимум 1 MAJOR cycle (≥ 6 мес) | пользователи мигрируют |
| 3. Remove | следующий MAJOR | breaking change в CHANGELOG |

**Исключение — security**: уязвимый API можно удалить раньше с явным notice + миграционный гайд.

---

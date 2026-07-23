# 5 измерений аудита

Справочник к скиллу `dependency-audit`.


### 1. Lockfile sanity

| Измерение | Что проверять |
|:---|:---|
| **Lockfile committed** | `package-lock.json` / `composer.lock` / `pubspec.lock` в git (для приложений — да; для библиотек — спорно, см. ниже) |
| **No phantom deps** | Все imports соответствуют зависимостям в манифесте, без транзитивных «случайно работает» |
| **No duplicates** | Одна версия каждой зависимости где возможно (`npm dedupe`, `composer why-not`) |
| **Pinned vs range** | `^1.2.3` для библиотек ОК, `=1.2.3` для приложений / lockfile-tracking |
| **Resolutions / overrides** | Зафиксировать в манифесте, не «магически» |

**Lockfile для библиотек:** есть две школы.
- **Не коммитить** (классика): пакет тестируется с разными версиями зависимостей, ловит conflicts раньше.
- **Коммитить** (современная практика, особенно для CI reproducibility): тесты деттерминированы, но dependabot обновляет.

**Рекомендация:** коммитить + dependabot + CI matrix по `peerDependencies` major-версиям.

### 2. Vulnerability scan

| Tool | Стек |
|:---|:---|
| `npm audit` / `pnpm audit` | JS |
| `composer audit` | PHP |
| `dart pub outdated --mode=security-fix` | Dart |
| `pip-audit` | Python |
| `osv-scanner` | универсальный, через CLI |
| GitHub Dependabot Alerts | репо-уровень |
| Snyk / Socket.dev | расширенный supply-chain |

**Политика:**
- `critical` / `high` уязвимости — **fix обязательно** до релиза
- `moderate` — fix желателен; если нельзя — задокументировать в `Dependency_Audit.md`
- `low` — журналировать, не блокировать релиз

**Транзитивные уязвимости без публичного fix:**
- Попытаться через `overrides` / `resolutions` форсировать safe-версию
- Если impossible — задокументировать в SECURITY.md и оценить exposure

### 3. License compatibility

Каждая зависимость должна иметь **известную совместимую лицензию**. Проверяется автоматически:

| Tool | Стек |
|:---|:---|
| `license-checker` | JS |
| `composer licenses` | PHP |
| `licensee` (gem) | универсальный по файлам |
| `fossa-cli` / `licensed` | enterprise-grade |

**Что искать:**

| Лицензия зависимости | Совместимость с MIT/Apache проектом |
|:---|:---|
| MIT, BSD-2/3, ISC, 0BSD, Apache-2.0 | ✅ ОК |
| MPL-2.0 | ✅ ОК (file-level copyleft, можно линковать) |
| LGPL-2.1+/3.0+ | ⚠️ Требует dynamic linking — для библиотек обычно ОК, проверить |
| GPL-2.0/3.0 | ❌ Заразит весь проект GPL |
| AGPL | ❌ Заразит, плюс SaaS-обязательства |
| BUSL, SSPL, Elastic | ❌ Не OSS — обычно блокирует commercial использование |
| Unknown / no SPDX | ⚠️ Запрос мейнтейнеру или замена |
| Custom proprietary | ❌ Обычно нельзя |

**Запрещённые лицензии для vault-OSS:** GPL, AGPL, BUSL, SSPL, любые «source-available».

### 4. Supply-chain risk

Беззвучно опасное:

| Риск | Как проверять |
|:---|:---|
| **Typosquatting** | `npm-name-deputy`, ручная проверка имени против популярных |
| **Maintainer turnover** | Сколько активных мейнтейнеров? Если 1 — bus factor проблема. |
| **Recent ownership transfer** | Smell-test: смена ownership + новые версии за < 30 дней = красный флаг |
| **Postinstall scripts** | `npm install --ignore-scripts` для CI; явный allowlist для prod |
| **Binary blobs** | Бинарники в node_modules / vendor — проверить хэши |
| **Unusual dependencies** | `colors` / `faker` / `event-stream`-class incidents — отслеживать `socket.dev` уровни |
| **Pre-1.0 dependencies в production пути** | Подсчитать; > 30% — риск |

**Защита:**
- Pin отдельные критичные deps на конкретную версию
- `npm install` с `--ignore-scripts` в CI, scripts явно разрешать
- `npm:cache:max-age` для предотвращения surprise updates

### 5. SBOM (Software Bill of Materials)

**Зачем:** требование для enterprise-потребителей (SOC2, FedRAMP), и просто хорошая практика для transparency.

**Формат:** CycloneDX (JSON) или SPDX. CycloneDX чаще встречается в OSS.

```bash
# JS
cyclonedx-npm --output-format JSON --output-file SBOM.json

# PHP
cyclonedx-php-composer --output-format=JSON --output-file=SBOM.json

# Dart
# нет официального — генерировать вручную из pubspec.lock либо использовать syft
```

**Что в SBOM:**
- Имя пакета, версия
- Лицензия (SPDX)
- Хэш
- Прямые vs транзитивные зависимости
- Origin (registry / git URL)

**Привязка к релизу:** SBOM публикуется как release artefact (рядом с tarball/zip). Обновляется при каждом релизе.

---

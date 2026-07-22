# Composer-специфика аудита зависимостей (PHP/Laravel)

Дополнение к `dependency-audit` для PHP-стека: конкретные команды и
composer-грабли по каждому из 5 измерений.

## Команды по измерениям

| Измерение | Команда | Примечание |
|:---|:---|:---|
| Vulnerability scan | `composer audit` | в CI: `composer audit --format=json --locked` |
| Outdated (прямые) | `composer outdated --direct` | `--major-only` для оценки техдолга |
| Лицензии | `composer licenses` | `--format=json` для отчёта |
| Phantom deps | `composer-require-checker check` | импорты без зависимости в манифесте |
| Неиспользуемые | `composer-unused` | зависимости без использования в коде |
| Почему пакет тут | `composer why <pkg>` / `composer why-not <pkg> <ver>` | разбор конфликтов и транзитивности |
| Платформа | `composer check-platform-reqs` | расширения PHP в prod vs lockfile |

## roave/security-advisories — гейт на этапе require

```bash
composer require --dev roave/security-advisories:dev-latest
```

Метапакет с `conflict` на все уязвимые версии: установка уязвимого пакета
падает сразу, а не ловится аудитом постфактум. Дополняет `composer audit`,
не заменяет (не покрывает уже установленное до его добавления — прогнать
`composer update --dry-run` после подключения).

## Reproducible installs

```bash
# CI/prod: строго по lockfile, без резолвинга
composer install --no-dev --prefer-dist --no-progress --no-interaction
# проверка, что lockfile синхронен с composer.json
composer validate --strict
```

- `composer.lock` для приложений — коммитить всегда; для пакетов — см.
  дискуссию в основном скилле + CI-матрица по версиям Laravel/PHP
  (`laravel-package-compatibility`).
- `composer validate --strict` в CI ловит рассинхрон manifest/lock.

## Composer-грабли (sharp edges)

- **`minimum-stability: dev`** в приложении — затягивает dev-версии
  транзитивно; использовать `prefer-stable: true` + точечные алиасы.
- **`repositories` с VCS/path** — код мимо Packagist: проверять источник
  и фиксировать commit-ref, не ветку.
- **Скрипты `post-install-cmd`/`post-update-cmd`** — исполняемый код при
  установке; в CI для недоверенных пакетов: `--no-scripts` + явный allowlist
  (`allow-plugins` в `config`).
- **`replace`/`provide` в форках** — пакет может тихо подменить другой;
  `composer why` покажет.
- **Wildcard-констрейнты (`*`, `>=`)** в require — нерепродуцируемые
  обновления; для библиотек `^x.y`, для приложений lockfile-дисциплина.

## SBOM для PHP

```bash
composer require --dev cyclonedx/cyclonedx-php-composer
composer make-bom --output-format=JSON --output-file=SBOM.json
```

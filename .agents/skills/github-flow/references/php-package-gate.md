# PHP Package Gate — релиз PHP-пакета на Packagist

Дополнение к `github-flow` для PHP-стека: что проверить в `composer.json`
и в каком порядке деплоить на Packagist. Laravel-специфика релиза —
`php-packages/laravel-package-release`; audit-команды — `dependency-audit/references/composer.md`.

## composer.json gate

Перед тегом агент проверяет наличие и корректность полей:

| Поле | Требование |
|:---|:---|
| `name` | `vendor/package-name`, совпадает с репозиторием на Packagist |
| `description` | одна строка, 50–100 символов, на английском |
| `type` | `library` (или `laravel-package` по конвенции инструментов) |
| `keywords` | 5–7 штук, см. ниже |
| `license` | SPDX-идентификатор (`MIT`, `Apache-2.0`) — совпадает с LICENSE |
| `authors` | name + email |
| `require.php` | явный констрейнт (`^8.2`), не «любая версия» |
| `autoload.psr-4` | namespace → `src/` |
| `minimum-stability` / `prefer-stable` | `stable` / `true` для публичного пакета |

```bash
composer validate --strict   # манифест валиден и синхронен с lock
```

**Поле `version`** — для Packagist опционально (версия берётся из git-тега).
Если поле присутствует, оно ОБЯЗАНО совпадать с тегом — агент синхронизирует
его в релизном коммите (`chore(release): ...`). Лучше поле не вести вовсе.

## Keywords

Базовые: `php` + экосистема (`laravel`, `symfony`). По домену — 2–4 уточняющих:

| Домен | Keywords |
|:---|:---|
| Permissions / ACL | `permissions`, `authorization`, `acl`, `rbac`, `roles` |
| Security | `security`, `middleware`, `threat-detection` |
| Auth | `authentication`, `auth`, `guards` |
| Bot / Telegram | `telegram`, `bot`, `webhook` |
| Admin panel | `filament`, `admin`, `dashboard` |
| API | `api`, `rest`, `resource` |

Больше ~10 Packagist эффективно не индексирует — не раздувать.

## Pre-deploy проверки (PHP)

- [ ] Нет `dd()`, `dump()`, `var_dump()`, `ray()` в `src/`:
  `grep -rnE '\b(dd|dump|var_dump|ray)\(' src/`
- [ ] Production-классы не используют dev-пакеты (`composer-require-checker`,
  см. `dependency-audit/references/composer.md`)
- [ ] `.gitignore` содержит `vendor/`, `.env`
- [ ] Тесты зелёные: `composer test` / `vendor/bin/phpunit` / `vendor/bin/pest`
- [ ] CI на default-ветке зелёный (`gh run list --branch main --limit 1`)

## Последовательность Packagist-деплоя

```
1. git checkout main && git pull
2. composer validate --strict
3. CHANGELOG.md: [Unreleased] → [X.Y.Z] - YYYY-MM-DD
4. (если поле version есть) composer.json → "version": "X.Y.Z"
5. git commit -m "chore(release): ..."
6. git tag vX.Y.Z -m "Release vX.Y.Z" && git push origin main --tags
7. gh release create vX.Y.Z из CHANGELOG-секции
8. Packagist подхватит тег по webhook
```

Webhook: Packagist → профиль пакета → «GitHub Hook» (ставится автоматически
при submit через GitHub-аккаунт). Проверка: после push тега новая версия
появляется на странице пакета за 1–2 минуты; если нет — `Settings → Webhooks`
в репо или кнопка «Update» на Packagist вручную.

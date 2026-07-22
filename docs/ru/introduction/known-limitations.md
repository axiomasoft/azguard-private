# Известные ограничения

- Локальные coverage и mutation проверки требуют PCOV или Xdebug; CI остаётся источником принудительной проверки.
- Snapshot публичного API сейчас покрывает только `core`; `filament` и `context` требуют отдельного follow-up.
- Legacy wildcard grammar доступна только на один deprecation cycle через `features.wildcard_permission = true`.
- Headless-режим остаётся doc-only: нулевая конфигурация панелей не ослабляет fail-closed поведение.
- `AzGuard::fake()` нельзя сочетать с blanket `Event::fake()`, иначе его listeners не получат события.

Ограничения не являются скрытыми дефектами релиза; для каждого определён безопасный follow-up или документированная причина. Полная англоязычная таблица: [Known limitations](/introduction/known-limitations).

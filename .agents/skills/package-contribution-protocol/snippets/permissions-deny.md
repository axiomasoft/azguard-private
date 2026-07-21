# permissions.deny: аппаратная граница записи в зависимый пакет

Блокирует запись агента в код пакета при работе в проекте-потребителе. Применяй
через готовый пресет (рекомендуется) или вставь правила вручную.

## Через пресет (рекомендуется)

```bash
harness permissions --target <project> --preset product-boundary
```

Пресет `settings.product-boundary.json` (пакет harness, `configs/claude-code/`) добавляет в deny:

```json
{
  "permissions": {
    "deny": [
      "Write(vendor/**)",
      "Edit(vendor/**)"
    ]
  }
}
```

## Вариант для path-репозитория (composer `path` / монорепо)

Если пакет подключён как локальный path-репозиторий и лежит в `packages/`, добавь
вручную в `.claude/settings.local.json` потребителя (НЕ включай это в общий
пресет — в самом репозитории пакета такой deny сломал бы разработку):

```json
{
  "permissions": {
    "deny": [
      "Write(packages/**)",
      "Edit(packages/**)"
    ]
  }
}
```

Сними этот deny, когда осознанно открываешь сессию разработки самого пакета
(отдельный контекст по `package-contribution-protocol`).

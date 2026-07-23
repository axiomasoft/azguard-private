# Шаг 1. Repo Structure

Справочник к скиллу `oss-development`.


Стандартная структура OSS-репозитория:

```
ProjectName/
├── src/                    # Исходный код
│   └── index.ts            # Точка входа
├── tests/                  # Тесты
├── docs/                   # Документация (если большая)
├── examples/               # Примеры использования
├── .github/
│   ├── ISSUE_TEMPLATE/
│   │   ├── bug_report.md
│   │   └── feature_request.md
│   ├── PULL_REQUEST_TEMPLATE.md
│   └── workflows/          # CI/CD
├── README.md               # Главный документ
├── CONTRIBUTING.md         # Как контрибьютить
├── CHANGELOG.md            # История изменений
├── LICENSE                 # Обязательно
└── package.json / go.mod / Cargo.toml
```

---

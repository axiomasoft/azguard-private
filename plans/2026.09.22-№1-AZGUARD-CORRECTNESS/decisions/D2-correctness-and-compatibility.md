---
id: D2
date: 2026-09-22
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7]
supersedes: []
superseded_by: null
---
# D2 — Исправления по наблюдаемому поведению с сохранением публичных швов

**Actor:** plan-designer/GPT-6
**Evidence:** RAG:— findings/verification.md; PermissionSet помечен @api, custom models уже опубликованы в config.

## Решение

Приоритет — identity/expiry, затем атомарность и consistency, затем поддержанные
extensions и lifecycle. Сохраняем Eloquent subclass-based model override contract.
Glossary различает definition/record/assignment без обязательного переименования PHP/SQL.
Строгая грамматика, новая role identity и schema evolution получают явный BC/migration
контракт на design соответствующей фазы. Не включаем автоматический schema/class rename.

## Почему

Замена GrantSource return type из аудита ломает сторонние источники. TTL-only repair
оставляет request cache без срока жизни. After-commit-only invalidation оставляет
failure window. Восемь registration traits, общий event envelope и role-version
vector не являются самоцелью: sol выбирает минимальный механизм с доказанным результатом.

## Consequences

Реализация начинается после закрытия локальных Q# выбранной фазы. Намеренно не обещаем
поддержку произвольных моделей без наследования, разных database connections,
MariaDB/fiber concurrency, если это не подтверждено support contract. Такие случаи
получают явный support verdict, а не скрытое «проверено».

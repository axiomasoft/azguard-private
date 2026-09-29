# Research: навигация и контроль качества

Исследования собраны 2026-09-29 для [дополнительного аудита](../additional-audit.md). Рабочая папка содержит два уровня доказательности:

- [Проверенные первичные источники](primary-sources.md), [срез dependency manifests](dependency-manifests.json), [инвентарь кода](repository-inventory.json), [поведенческие probes](behavior-probes.json) — факты о документации и текущем коде в обозначенных пределах.
- [Предварительный синтез Perplexity](synthesis/) — архитектурные альтернативы и источники для Opus. Каждый ответ содержит исходный вопрос. Ранние R01–R30 задавались до предъявления Perplexity конкретных файлов и часто содержат шаблонные вводные «нет доступа к репозиторию». Их **нельзя** использовать как доказательство текущего поведения. F31–F33 — точные follow-up с кодовым сценарием. Сырые ответы этих трёх сохранены в [raw](raw/) для сверки с опубликованным текстом.

Опорные утверждения дополнительного аудита проверены отдельно по коду/документации. В первоначальном наборе каждая оставленная тема отвечает на **отдельный** вопрос; детальные ответы могут упоминать соседние темы, но их основная задача различна.

## Тематический указатель

| Исследование | Единственный основной вопрос | Как использовать |
|:--|:--|:--|
| [R01](synthesis/R01-api-spi-boundaries.md) | Что является user API, extension SPI и internal у трёх Composer packages | Выбор публичной границы; код проверять по inventory |
| [R02](synthesis/R02-bc-contracts.md) | Что именно контролирует PHP BC tool и какие semantic fixtures нужны | Контрактные проверки, не источник API facts |
| [R03](synthesis/R03-eloquent-administration.md) | Как поддерживать Eloquent writes и административный mutation boundary | Общие Laravel обходы; конкретный RolePermission разбирает F33 |
| [R04](synthesis/R04-revocation-consistency.md) | Какую consistency guarantee может дать DB revision+cache | Общая модель гонок; текущий replica gap — F32 |
| [R05](synthesis/R05-outbox-events.md) | Когда afterCommit достаточно, а когда нужен durable outbox | Гарантия доставки событий |
| [R06](synthesis/R06-context-lifecycle.md) | Чем scoped lifecycle отличается от nested/concurrent context scope | Runtime contract; конкретный cleanup bug — F31 |
| [R07](synthesis/R07-tenant-security.md) | Где граница доверия tenant/context и membership | Threat model и негативные сценарии |
| [R08](synthesis/R08-gate-coexistence.md) | Как согласовать tri-state Gate с другими Laravel policies | Выбор additive/authoritative mode |
| [R09](synthesis/R09-filament-adapters.md) | Как Filament Resource читает Eloquent и направляет записи в сервис | UI mutation seam |
| [R10](synthesis/R10-permission-grammar.md) | Как задавать local/qualified/wildcard permission grammar | Миграция ключей и property tests |
| [R11](synthesis/R11-role-identities.md) | Как развести machine role key, DB id, PHP class и label | Identity при переименованиях |
| [R12](synthesis/R12-schema-upgrades.md) | Как владеть миграциями и install/upgrade lifecycle | UX установки и upgrade fixture |
| [R13](synthesis/R13-database-portability.md) | Как сохранить uniqueness across PostgreSQL/MySQL/SQLite | Схема и реальные engine tests |
| [R14](synthesis/R14-package-matrix.md) | Как выявлять missing dependency в monorepo path packages | Consumer fixtures; точные constraints в manifests |
| [R15](synthesis/R15-mutation-properties.md) | Как интерпретировать выбранный denominator Pest mutation | Отчёт о качестве и property tests |
| [R16](synthesis/R16-expiry-clock.md) | Как absolute expiry взаимодействует с TTL и часами | Temporal contract |
| [R17](synthesis/R17-performance-benchmarks.md) | Как измерить цену глобальной revision до оптимизации | Benchmark specification |
| [R18](synthesis/R18-diagnostics-privacy.md) | Что должно входить в explain/doctor и что нельзя логировать | Объяснимость и приватность; перепроверять текущие hooks |
| [R19](synthesis/R19-immutable-catalog.md) | Когда фиксировать panel/catalog definition и как версионировать | Registry lifecycle, отличается от permission grammar |
| [R20](synthesis/R20-extension-design.md) | Как компоновать несколько restricting extensions | SPI composition, отличается от алгебры итогового решения |
| [R21](synthesis/R21-reference-systems.md) | Какие узкие уроки дают Spatie/Cedar/Symfony/OpenFGA/Zanzibar | Сравнение принципов без предложения внешнего сервиса |
| [R22](synthesis/R22-delegation-escalation.md) | Кто вправе выдавать конкретное право другому subject | Actor/subject/tenant/superadmin policy |
| [R23](synthesis/R23-query-scopes.md) | Где Eloquent row scope перестаёт быть security boundary | Visibility vs object authorization |
| [R25](synthesis/R25-architecture-variants.md) | Как сравнить формы всей системы при свободном redesign | Предварительный стиль; конкретный выбор — в architecture-options |
| [R26](synthesis/R26-configuration-contracts.md) | Как задать typed effective settings и override precedence | Configurability, отдельна от хранения |
| [R27](synthesis/R27-naming-and-api-ergonomics.md) | Каким словарём описывать user-facing API | Названия и вызовы; один источник в ответе, проверять отдельно |
| [R28](synthesis/R28-storage-port-design.md) | Какой storage port действительно позволяет alternate backend | Отличается от R03: контракт заменяемого хранилища |
| [R29](synthesis/R29-decision-algebra.md) | Как grant union, deny/abstain/error и mandatory constraints дают итог | Семантика engine, отличается от R20: plugin wiring |
| [R30](synthesis/R30-identity-codec.md) | Как сделать injective typed identity и версионировать ключ | Коллизия C01; точная постановка |
| [F31](synthesis/F31-cleanup-before-finally.md) | Как восстановить context, если entry invalidation бросает | Новая проверка конкретного сбоя C02 |
| [F32](synthesis/F32-revision-and-source-primary.md) | Должны ли revision **и** grants читаться с primary | Новая проверка текущего read/write gap C03 |
| [F33](synthesis/F33-documented-rolepermission-write.md) | Поддерживать ли документированный прямой RolePermission write | Новая проверка расхождения docs/API C04 |

## Что отсеяно и почему

- R24 был общим чеклистом release readiness и повторял R02/R14/R12 без специфичного нового механизма.
- R31 повторял Eloquent/Filament adaptation R09, добавляя широкий UX перечень без отдельного решения.
- R32 повторял request/job lifecycle R06 и настройки R26.
- Точечный ответ F34 предложил отдельно читать `PermissionSet` через resolver и снова опрашивать источники ради trace — противоречит требованию одного snapshot. F35 предлагал зашить ровно два constraint slots, хотя приоритет владельца — extensibility. F36 вернулся неполной CSV-таблицей без требуемого вывода. Эти ответы удалены; [дополнительный аудит](../additional-audit.md) и [варианты архитектуры](../architecture-options.md) дают собственный вывод по этим вопросам.

Такой отбор сохраняет полезные исследования без повторного запуска дорогих широких запросов. **Не переносить** в итоговый дизайн фразу из Perplexity только потому, что она сохранена здесь: сначала код или конкретный первичный источник, затем решение с явной пометкой inference.

## Воспроизводимость

```bash
python3 audits/2026-09-29-audit/Research/build-inventory.py --check
python3 audits/2026-09-29-audit/Research/build-dependency-manifests.py --check
php audits/2026-09-29-audit/Research/behavior-probes.php --check
```

Inventory — ограниченный source scan top-level types/imports. Dependency manifests — снимок официальных upstream-файлов; повторная проверка может обнаружить изменения их веток. Probe не проверяет БД, Redis или реальное повышение привилегий. [Открытые точные вопросы](queries.json) содержат только F31–F33; их ответы и сырой протокол рядом.

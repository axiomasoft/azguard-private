# Возможная эволюция AzGuard

Источник: PLAN1.D2, `audits/2026-09-22-audit.md`. Эти идеи не входят в acceptance
correctness-плана. Возврат к ним требует наблюдаемого ограничения или отдельного запроса.

| Идея | Почему отложена | Когда вернуться |
|:--|:--|:--|
| Массовые class/table/column renames | Совместимость и upgrade cost без исправления ошибки доступа | Утверждён major/migration проект |
| Полная immutable compilation Panel DSL | Полезное направление, но lifecycle fix не требует нового публичного DSL | Измеренная runtime mutation / catalog inconsistency |
| Feature-first перенос всех namespaces, восемь provider traits | Структурная косметика добавляет churn и API риск | Конкретное усложнение owning модуля |
| Общие schema registry, exception/event envelope, mutation IDs | Не нужны всем поддержанным сценариям; могут разрастить API | Повторяемый consumer use-case |
| Transactional outbox для внешнего audit trail | Нет заявленного durable external audit consumer; не решает stale cache немедленно | Требование внешней гарантированной доставки |
| Общий axioma-support / изменения Chatom | Соседний проект здесь не исследован и не является целью реализации | Отдельная межпроектная задача с контрактами |
| Обязательная morph-map/namespace allowlist для всех | Меняет поддержку существующих приложений | Threat model и путь перехода |
| Резкий рост PHPStan/coverage thresholds, SBOM/signing | Нет измеренной стоимости и baseline для нового гейта | Отдельная qualification/release потребность |
| Удаление level / class-as-identity автоматически | level — часть public role contract, FQCN ломается при rename | Решение PLAN1.Q7 и consumer evidence |

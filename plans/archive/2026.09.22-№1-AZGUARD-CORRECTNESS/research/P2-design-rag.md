# Внешние подтверждения P2 — transactions, events and cache locks

Сырой capture: `../artifacts/P2-design-rag/capture.md`. Нормативные решения — D13 и
`P2-design.md`; retrieval text служит указателем на primary sources.

## Проверенные выводы

- `DB::transaction()` атомарна только в пределах выбранной DB connection; исключение откатывает
  closure, deadlock retry может повторить body.
- Root after-commit callback упорядочен после успешного commit, но не является durable delivery,
  не включает Redis/cache в транзакцию и не гарантирует retry после process crash.
- Eloquent mass update/delete не создаёт обычный per-model lifecycle; observers нельзя считать
  authoritative invalidation для bulk path.
- Laravel cache lock координирует только cooperating users общего backend; timeout/error не делает
  DB+cache atomic.
- Filament 5 database transactions opt-in, поэтому package mutation не может полагаться на host panel.

## Вывод для AzGuard

Transactional DB revision на той же connection — correctness fence. Cache invalidation и locks
остаются advisory optimization. Outbox нужен для durable external delivery, но не требуется для
синхронного resolver, который перед каждым hit читает committed revision.


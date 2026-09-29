# Карта решений для итоговой проработки Opus

Эта карта задаёт направления выбора; она не фиксирует итоговые contracts и не запускает implementation. Исследования находятся в [Research](Research/README.md), кодовые наблюдения — в [дополнительном аудите](additional-audit.md).

| ID | Решение | Альтернативы / вопрос | Рекомендация Codex | Research |
|:--|:--|:--|:--|:--|
| D01 | Target architecture | Laravel-native modules / kernel+adapters / policy engine | Сравнить A и B; предварительно B | R21, R25 |
| D02 | Distribution boundaries | 3 текущих packages / дополнительный kernel / новые slices | Границы по consumers/release/API, число не фиксировано | R01, R14, R25, R28 |
| D03 | API vs SPI | Shared public surface / extension-specific contract / friend modules | Explicit export map и dependency checks | R01, R02 |
| D04 | Scope of purity | Laravel dependencies в kernel / только adapters | Чистые identity/request/decision; framework-native adapters | R25, R28, R06 |
| D05 | Names | Panel/namespace, Permission/ability, Actor/subject | Один glossary и distinct role/context concepts | R11, R27 |
| D06 | Permission input | Строка всегда qualified / два typed forms / explicit local method | Исключить угадывание semantics по runtime type | R10, R27 |
| D07 | Grammar | Текущая hierarchical / configurable profiles / custom matcher | Versioned grammar и validated namespace ID | R10, R26 |
| D08 | Identity encoding | Colon strings / canonical tuples / structured refs | Typed canonical payload до digest; fix C01 | R13, R30 |
| D09 | ID equality | `7` = `'7'` или разные; UUID case; morph alias | Определить по storage/domain identity, проверить collision properties | R13, R30 |
| D10 | Registry lifecycle | Mutable runtime definitions / freeze at boot / explicit reload | Immutable registered catalog/panel, deliberate replacement | R19, R06 |
| D11 | Config assembly | Static accessors / immutable effective settings | Typed validated settings и documented precedence | R19, R26 |
| D12 | Source composition | Priority union / semantic resolver strategy | Отделить additive grants от mandatory restrictions | R20, R29 |
| D13 | Restriction extensions | Single PermissionLayer / chain / named constraints | Explicit composable registry; ordering/error contract | R20, R26, R29 |
| D14 | Superadmin | Global wildcard / namespace override / break-glass policy | Explicit bypass scope, not incidental source value | R07, R20, R22, R29 |
| D15 | Gate integration | Additive no-grant null / owned authoritative false | Выбрать documented mode; чужим abilities — abstain | R08, R29 |
| D16 | Tenant boundary | Context selects grants / verifies membership / data isolation | Три разные guarantees; membership contract отдельно | R07, R23, R06 |
| D17 | Ambient state | Scoped globals / explicit request / runtime-local context | Explicit core input; adapter run/finally для convenience | R06, R06 |
| D18 | Context cleanup | Частный check wrapper / общий execution scope | Исключение на любой стадии восстанавливает state; C02 | R06, R06 |
| D19 | Role identity | Class FQN / panel:name / immutable RoleKey + binding | RoleKey отдельно от label/class/record ID | R11, R27 |
| D20 | Administration security | Manage-all / delegation policy / explicit actor grants | Separate Actor and Subject; server-side policy | R07, R22, R09 |
| D21 | Supported writes | Eloquent direct / services only / controlled maintenance imports | Один atomic mutation entry point; перечислить bypasses | R03, R04, R28 |
| D22 | Store portability | Custom subclasses only / interchangeable store | Объявить capabilities и atomicity; произвольные stores не притворяются одинаковыми | R13, R28 |
| D23 | Revision freshness | Primary / read replicas / bounded eventual | Выбрать guarantee и authoritatively read всю dependency цепочку | R04, R06 |
| D24 | Revision topology | Global / subject+panel / dependency vector | Глобальный baseline; finer topology после benchmark | R04, R17, R28 |
| D25 | Cache correctness | PermissionSet / decision cache / source snapshots | Полные dependencies, volatility, generation и expiry | R04, R16, R19, R30 |
| D26 | Event delivery | Inside tx / afterCommit / durable outbox | Развести process event, integration event и audit requirement | R05, R18 |
| D27 | Explain semantics | Requery sources / trace same evaluation | Один decision/state snapshot, redaction | R18, R29 |
| D28 | Filament persistence | Existing Eloquent resources / projection models / service Pages | Native reads + API writes; generic storage требует отдельного решения | R09, R28, R09 |
| D29 | Filament customization | Hard-coded provider / replaceable resources and pickers | Configured provider/subject mapper, per-panel settings | R09, R26, R09 |
| D30 | Query scopes | Permission engine / visibility adapter / DB RLS | Явный visibility contract; object policy отдельно | R07, R23 |
| D31 | Schema upgrade | Reset now / append-only / publish-custom migrations | Fresh schema и upgrade schema имеют разные tests/guarantees | R12, R13 |
| D32 | Install UX | Publish-only / confirmed migrate / package-only migrate | Явный scope и exit-code contract | R12, R14 |
| D33 | Compatibility checks | Reflection only / BC tool / semantic fixtures | PHP + config/events/CLI/schema + consumer scenarios | R02, R14, R14 |
| D34 | Mutation report | One percentage / selected denominator and scenarios | Показывать область, exclusions, uncovered и surviving mutants | R15 |
| D35 | Runtime support | HTTP only / queues/CLI/Octane / coroutine-safe guarantee | Supported profiles и capability checks; не путать scoped с fiber-local | R06, R06 |
| D36 | Performance evidence | Query counts / latency profiles / write contention | Cold/warm p95/p99, source fan-out, mutation contention | R17 |
| D37 | Stable release criteria | Version number / contract readiness / migration rehearsal | Проверяемые promises и consumer evidence, потом release | R14, R14 |

## Противоречия, которые итоговый дизайн должен устранить

1. «ContextOnly» против global wildcard, обходящего context: определить override semantics.
2. «Model classes customizable» против internal persistence: выделить supported adapter surface или отказаться от обещания arbitrary models.
3. «Storage replaceable» против Eloquent Resource: выбрать projection adapter или service Pages.
4. «Immediate revoke» против replica reads и in-flight decisions: точно обозначить consistency boundary.
5. «Pure kernel» против VO, читающих `app()/config()`: передать зависимость или отнести объект к adapter layer.
6. «Clean thin API» против десятков interchangeable switches: разделить invariants, profiles и extension ports.
7. «SemVer snapshot» против непроверенной семантики/default values/events: выбрать набор contracts и enforcement.
8. «Explain final allow/deny» против additive Laravel Gate: маркировать local result и final host result отдельно.
9. «Durable audit» против синхронного event dispatcher: сформулировать delivery guarantee.
10. «Scoped is safe» против nested calls и coroutines: определить execution scope и cleanup responsibility.

Итоговая проработка может отвергнуть мои предварительные рекомендации. Для этого достаточно объяснить trade-off и показать, как другой вариант удовлетворяет приоритетам владельца и конкретным failure scenarios.

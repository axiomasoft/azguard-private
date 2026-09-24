---
id: D1
date: 2026-09-22
status: accepted
item: P1
items: [P1, P2, P3, P4, P5, P6, P7]
supersedes: []
superseded_by: null
---
# D1 — Основной слой сейчас; детализация фаз через sol

**Actor:** owner-request / plan-designer GPT-6
**Evidence:** RAG:— запрос владельца: «task plan design основного слоя, детализация каждой фазы будет через модели sol».

## Решение

Создать contract-first мастер-план с семью скелетными фазами. Команда детализации
каждой фазы маршрутизируется на gpt-5.6-sol/high. Эта сессия выполняет только основной
дизайн и необходимую проверку фактов; не запускает implementation или successor-agent.

## Почему

Полная детализация сейчас повторила бы будущую работу sol и заморозила непроверенные
implementation choices. Простой список пунктов аудита не задаёт безопасность и зависимости.
Основной слой фиксирует acceptance boundaries, оставляя конкретные алгоритмы owning фазам.

## Consequences

P1–P7 сохраняют skeleton marker. Next — design P1. Факт модели автора не подменяется
моделью будущего исполнителя; точный selector/effort текущей root-сессии не аттестован.

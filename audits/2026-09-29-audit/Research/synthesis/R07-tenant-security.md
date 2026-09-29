# R07 — Tenant membership и граница доверия контекста

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Multi-tenant authorization architecture in Laravel: tenant/context resolver from route/session/header does not prove membership. Cross-tenant object IDOR, subject tenant and resource tenant mismatch, tenant-switch, background jobs, impersonation, break-glass. Contrast RBAC+context with ABAC/ReBAC, specify invariants and negative scenarios. Include admin management authorization, separate tenant data isolation from permissions.

## Ответ Perplexity

Дата исследования: 2026-09-29. Это обзор архитектурных требований, а не аудит кода: сведения о трёх Composer-пакетах, PHP 8.3+, Laravel 11/12/13, revision fence, поколениях кэша, expiry envelopes, PermissionLayer и discriminator приняты из вашего описания и здесь независимо не проверялись. Доступ к приватному репозиторию не заявляю.

## Документированные факты

Laravel разделяет авторизацию на gates и policies: gates подходят для общих действий, policies — для решений по конкретной модели/ресурсу. Laravel позволяет передавать policy дополнительный контекст; это механизм передачи аргументов, но не гарантия, что переданное значение истинно или что модель принадлежит выбранному tenant. Документация также описывает глобальный Gate::before и before policy, которые могут обходить обычные проверки, если администратору безусловно вернуть true. Источники: Laravel 12.x Authorization, версия документации 12.x, проверено 2026-09-29 и Laravel 13.x Authorization, опубликовано 2026-09-24.
OWASP указывает, что tenant ID из запроса следует рассматривать как селектор, а не как доказательство доступа: нужна проверка членства или полномочий субъекта в выбранном tenant. Для tenant-owned объектов OWASP рекомендует включать tenant scope в lookup или policy; непрозрачный ID не заменяет авторизацию. Для асинхронной обработки следует восстановить проверенный контекст у consumer и повторно проверять права, если они могли измениться. Источник: OWASP Multi-Tenant Application Security Cheat Sheet, актуальная веб-редакция проверена 2026-09-29.
NIST определяет ABAC как оценку атрибутов субъекта, объекта, операции и иногда среды по правилам/политикам. Это позволяет выразить tenant, membership, классификацию ресурса, действие и временные условия в одном решении. Источник: NIST SP 800-162, обновление от 2019-08-02.

## Архитектурная оценка

Tenant/context resolver из route, session или header лишь сообщает какой контекст запрошен. Сам по себе он не удостоверяет membership. Нужна проверяемая цепочка: аутентифицированный principal → активное членство/сервисное разрешение → выбранный tenant → конкретный ресурс. Иначе корректный RBAC-ответ вроде «у пользователя есть document.update» может разрешить запись чужого tenant.
Минимальный инвариант для обычного tenant-scoped решения:
allow = authenticated ∧ activeMembership(subject, context.tenant) ∧ context.tenant = resource.tenant ∧ permission(subject, action, resource, context)
Для ресурсов, где есть легальное совместное владение или cross-tenant sharing, равенство tenant ID следует заменить явным проверяемым relationship/делегированием, а не неявным исключением. Это архитектурный вывод, а не поведение, которое гарантирует Laravel.
RBAC + context обычно достаточен, если роли и permissions устойчивы и решение сводится к membership в tenant плюс permission на действие. ABAC нужен, когда важны атрибуты ресурса/субъекта и условия: статус записи, подразделение, чувствительность, время, делегирование. ReBAC уместен, если доступ определяется графом отношений — владелец, участник группы, родительский объект, совместный доступ. Практичный вариант — RBAC для базовых полномочий и явные ABAC/ReBAC-проверки для границ объекта; не следует превращать tenant context в скрытый глобальный bypass.

## Инварианты и негативные сценарии

- IDOR: загрузка по id без tenant-фильтра запрещена. Проверять, что объект найден в разрешённом tenant, либо что relationship даёт явный доступ; ответ 404 вместо 403 может скрывать существование объекта.
- Несовпадение tenant субъекта и ресурса: пользователь состоит в A, но передал ID объекта B — отказ независимо от успешного resolver и наличия одноимённого permission.
- Создание/перенос: клиент не может подменить tenant_id в payload, route binding или relation. Перенос между tenant — отдельная привилегированная операция.
- Tenant switch: смена активного tenant требует повторной проверки membership и сброса/пересчёта tenant-зависимых решений. Подмена header или replay старой сессии не должна сохранять доступ к прежним полномочиям.
- Jobs: payload с tenant ID не является доказательством. Связывать job с доверенным producer/actor или ограниченной service identity; восстановить scope в worker, проверить target и актуальные права. Нет контекста — fail closed.
- Impersonation и break-glass: не считать их обычным tenant admin. Требовать отдельное право, причину/срок, аудит исходного и эффективного субъекта, ограничение tenant и явный путь выхода; отсутствие/просрочка контекста — отказ.
- Управление админами: создание ролей, назначение permissions, приглашение/удаление участников и изменение tenant settings — самостоятельные abilities, проверяемые для целевого tenant. manage-users не должен означать platform-wide доступ.

## Границы и пробелы аудита

Авторизация отвечает «может ли субъект выполнить действие», а изоляция данных — «какие строки/схемы/объекты вообще достижимы». Нужны оба слоя: tenant-scoped queries/repositories и, где подходит, DB RLS/разделение схем; плюс tenant namespace для кэша, файлов и очередей. Наличие revision fence, cache generation и expiry envelope из описания полезно для свежести/инвалидации, но само по себе не доказывает корректность tenant scope.
По текущему контексту неизвестно, как три пакета разделяют resolver, decision point и enforcement; какие API обходят PermissionLayer; как определены платформенные субъекты и break-glass; и как revision fence охватывает смену membership, impersonation и jobs. Для проверки нужны матрица субъект × tenant × ресурс × действие и отрицательные тесты: A→ID B, revoked membership, смена tenant на worker, подложный tenant_id, stale cache/revision и попытка tenant-admin управлять пользователями другого tenant.

Sources:
1. [Laravel 12.x Authorization, версия документации 12.x, проверено 2026-09-29](https://laravel.com/framework/docs/12.x/authorization)
2. [Laravel 13.x Authorization, опубликовано 2026-09-24](https://laravel.com/framework/docs/authorization)
3. [OWASP Multi-Tenant Application Security Cheat Sheet, актуальная веб-редакция проверена 2026-09-29](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html)
4. [NIST SP 800-162, обновление от 2019-08-02](https://csrc.nist.gov/pubs/sp/800/162/upd2/final)

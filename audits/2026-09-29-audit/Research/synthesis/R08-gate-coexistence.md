# R08 — Laravel Gate и совместимость policies

**Статус:** предварительный Perplexity synthesis, сохранён из этой сессии. Вопрос сформулирован на уровне области, без точных фрагментов текущего кода. Советы о локальном AzGuard использовать только после сверки с [репозиторием](../repository-inventory.json) и [первичными источниками](../primary-sources.md). Пересечения с другими темами смотрите в [карте исследования](../README.md).

## Исходный вопрос

Laravel 11/12/13 Gate::before tri-state semantics true/false/null, Gate::after, allowIf/denyIf inline checks, super-admin and unknown ability ownership. RBAC package own namespace versus Laravel model policy abilities view/update/delete. Filament 5 policies and bulk actions. Safe ownership classifier and tests for coexistence with another before callback; no assumption deny can be overridden.

## Ответ Perplexity

## Вывод

Для библиотеки авторизации, которая должна сосуществовать с Laravel и чужими пакетами, безопасная граница — не пытаться глобально переопределять результат любых проверок Gate. Laravel документирует Gate::before как ранний перехват, где любое значение, отличное от null, становится результатом проверки; это включает false. Следовательно, отказ другого callback нельзя считать «мягким» и отменять без явного владения этой ability. Это вывод для проектирования, а не утверждение о вашей закрытой реализации.

## Документированное поведение Laravel

В документации Laravel 11.x и 12.x before вызывается до обычных Gate/policy-проверок. Возврат true или false завершает проверку; null позволяет ей продолжиться. У after другая семантика: результат его callback не переопределяет уже полученный результат, кроме случая, когда Gate или policy вернули null. Поэтому after не является надёжным способом превратить отказ в разрешение.
Gate::allowIf и Gate::denyIf выполняют inline-проверки и, согласно документации обеих версий, не запускают зарегистрированные before и after hooks. Это означает, что интеграцию через hooks нельзя считать универсальным перехватчиком всех авторизационных решений.
Laravel рекомендует Gates для действий, не привязанных к модели, а policies — для действий над конкретной моделью или ресурсом. Документация использует стандартные model abilities вроде view, update, delete и указывает, что policy выбирается по модели. Поэтому RBAC-способности библиотеки разумно держать в собственном документированном namespace, а не присваивать себе общие CRUD-имена: это уменьшает риск конфликта с приложением и Laravel policies. Это архитектурный вывод из описанного разделения, не требование фреймворка.

## Super-admin и владение ability

Широкий super-admin before, возвращающий true для каждой ability, может обходить не только RBAC-правила, но и отказы policies или других before callbacks — порядок регистрации и конкретный набор callback’ов приложения становятся существенными. Для библиотеки предпочтительнее классифицировать ability как принадлежащую ей только при явном совпадении с её namespace/реестром, а для остальных возвращать null. Это рекомендация, а не гарантированное свойство Laravel.
Не следует трактовать незнакомую ability как разрешённую либо как принадлежащую библиотеке по сходству имени. Например, для view, update или delete с моделью лучше оставить решение policy; RBAC может быть отдельной проверкой, которую приложение вызывает явно или связывает с policy на своей стороне. false от собственного classifier’а тоже опасен: в before это терминальный отказ, а не «не найдено». В частности, нельзя утверждать, что библиотечный true в другом callback сможет отменить такой отказ: официальное описание Laravel этого не обещает.

## Filament 5 и bulk actions

Filament 5 сообщает, что стандартные CRUD-операции ресурсов используют зарегистрированные model policies. Для bulk-delete используется deleteAny() вместо проверки delete() на каждой записи ради производительности; для индивидуальной авторизации доступен authorizeIndividualRecords(). Это важно: корректность одиночной policy-проверки сама по себе не доказывает, что массовая операция проверяет каждую запись.

## Рекомендации для аудита и пробелы

Кандидат на безопасный classifier: проверять точное пространство имён собственной библиотеки; возвращать true или false только для явно принадлежащих ей abilities; иначе — null. Проверить его тестами на чужой before, зарегистрированный до и после библиотечного callback, включая true, false и null; на policy abilities view/update/delete; на неизвестные и похожие по имени abilities; на allowIf/denyIf; и на Filament bulk actions с deleteAny и authorizeIndividualRecords().
Не установлено этой проверкой: точный порядок регистрации callbacks во всех поддерживаемых Laravel 11/12/13 конфигурациях, фактическая реализация вашей библиотеки и совместимость каждого из трёх Composer-пакетов. Laravel 13.x исходник Gate не удалось независимо проверить по полученной странице; поэтому семантику 13.x нельзя здесь выдавать за отдельно подтверждённый факт. Сверьте её с конкретной версией illuminate/auth из Composer lock и закрепите поведение интеграционными тестами. Версии источников: документация Laravel 11.x/12.x и Filament 5.x, дата аудита — 2026-09-29.

Sources:
1. [Laravel 11.x Authorization](https://laravel.com/docs/11.x/authorization)
2. [Laravel 12.x Authorization](https://laravel.com/docs/12.x/authorization)
3. [Filament 5: Security](https://filamentphp.com/docs/5.x/advanced/security)
4. [Filament 5: Deleting records](https://filamentphp.com/docs/5.x/resources/deleting-records)
5. [Filament 5: Table actions](https://filamentphp.com/docs/5.x/tables/actions)

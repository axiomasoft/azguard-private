---
id: D10
date: 2026-10-02
status: accepted
item: P2
items: [P2, P2.4, P2.5, P2.6, P2.8, P2.10]
supersedes: []
superseded_by: null
---
# D10 — У плагина нет префикса ключей: без `PrefixesKeys`, `BasePlugin::prefixed()` и `PluginContext::namespace()`

**Actor:** owner (ответ 2026-10-02 на вопрос о `BasePlugin::prefixed('blog')`: «Убрать префикс плагина»; сообщение «но возможно у плагина будет ->id()») / Claude Opus 5.5 (frontier)
**Evidence:** D9 п.1 (`PermissionKey::prefixed()` отменён; плагинный `prefixed()` был оставлен до ответа владельца); RAG:— `06-extension-points.md` §3 (`PrefixesKeys`, `BasePlugin::prefixed()`/`prefix()`), §8 (модуль Blog с `prefixed('blog')`); `18-contexts-and-runtime-inputs.md` §10 (`PluginContext::namespace()`); `02-decisions.md` D05 («префикс плагина вкладывается внутрь»), D47, D50, D84 («Plugin.prefixed() — отдельный namespace локальных keys»); `14-verification.md` V55, V81, V102; `05-php-api.md` §10 (`DuplicatePermissionException`, `DuplicateRoleException` — «коллизии вкладов (с именами плагинов)»); спецификации P2.4, P2.5, P2.6, P2.8, P2.10 на HEAD `cf0c2a9`.

## Solution

1. **Префикса ключей у плагина нет.** Не создаются интерфейс `Contracts\Plugins\PrefixesKeys`, методы
   `BasePlugin::prefixed(string): static` и `BasePlugin::prefix(): ?string`, метод `PluginContext::namespace()`.
   `PluginContext` несёт `panelId()`, `pluginId()`, `buildId()`, `dependencies()`. `BasePlugin` остаётся общей базой
   жизненного цикла (`boot()` по умолчанию пустой) без `make()`/`options()`/`withOptions()` (D47).
2. **Ключи вкладов плагина попадают в каталог как объявлены.** Права, роли, строки `Role::permissions()` и привязки
   политик плагина не преобразуются. Модуль сам называет свои права и роли в своём пространстве имён: значение enum
   `blog.posts.edit`, ключ роли `blog-editor`. Одинаковое имя у двух вкладов (провайдер и плагин, два плагина) —
   `DuplicatePermissionException` / `DuplicateRoleException` с id плагинов (P2.5).
3. **Происхождение записи рецепта** вида `plugin` несёт id плагина и порядок подключения; поле `prefix`, введённое
   в P2.1 (`PanelRecipe::plugin(id, order, prefix)`), удаляет P2.4. В отпечаток панели входят id плагинов в порядке
   подключения, без префиксов.
4. **Сценарии досье в части префикса плагина меняются:**
   - V55 — модуль подключает плагин без `prefixed()`; права модуля — `blog.posts.edit`; два модуля с одним именем
     права дают `DuplicatePermissionException` с id обоих плагинов, а не два разных ключа;
   - V81 — части «плагин `prefixed('blog')` в панели с префиксом» нет; имя `admin.blog.posts.edit` приводится к
     `admin:blog.posts.edit` обычным снятием префикса панели, потому что локальное имя модуля — `blog.posts.edit`;
   - V102 (статичная часть) — enum, строки ролей и привязки дают один ключ без преобразований.
5. **Id плагина.** `Plugin::id(): string` остаётся в SPI (06 §3) и остаётся единственной идентичностью плагина:
   по нему работают `withoutPlugins()`, зависимости, конфликты и происхождение `plugin:<id>`. Fluent-сеттер
   `->id('…')` на базе владельцем назван как возможный, но не решён: P2.4 его не вводит. Если владелец решит его
   ввести, это отдельная поправка до старта P2.4 (сигнатура `id()` геттера и сеттера в PHP одна, форму нужно выбрать).

## Why

Решение владельца: у префикса имён одно место и одно имя — панель и `resourcePrefix()` (D9); второе пространство
префиксов на уровне плагина убирается. Это снимает целый класс преобразований (enum → ключ, строки ролей, привязки
политик, runtime-вклады источников плагина), которые пришлось бы делать одинаково в каталоге, `FolderSource` и
пайплайне, и делает ключ в коде модуля равным ключу в каталоге и в БД. Цена — модуль обязан сам выбирать имена без
столкновений; столкновение не молчит, а даёт ошибку сборки с именами плагинов.

## Consequences

Спецификации P2.4 (Intent, Why, Scope, Files, Implementation Rules, Code Guidance), P2.5, P2.6, P2.8, P2.10 и
`phases/P2/P2.md` изменены до старта B2b; закрытые P2.1–P2.3 не затронуты. В D8 теряют силу: в п.1 «префикс плагина
внутри префикса панели — P2.8», в п.7 «V102 — … префикс для runtime-вкладов источника плагина решает детализация
P4.1» и «V81 … `admin.blog.posts.edit` — P2.8» в части вложенного префикса; остальное в D8 в силе. Таблица `12-operations-and-release.md` `catalog.collisions` читается без «с учётом `prefixed`».
Примеры интеграций `10-integrations.md` и документация P8.2/P8.4 пишутся без `->prefixed()`; фикстуры модулей Blog и
Shop (P2.6) объявляют имена в своём пространстве. `tests/Unit/Panels/PanelBuilderTest.php` (проверка формы
происхождения с `prefix`) правит P2.4.

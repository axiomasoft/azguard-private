# Blade-директивы

AzGuard работает со всеми стандартными Blade-директивами Laravel Gate и добавляет собственные `@az*`-директивы.

## Стандартные директивы Laravel Gate

```blade
@can('app.posts.edit')
    <a href="{{ route('posts.edit', $post) }}">Редактировать</a>
@endcan

@cannot('app.posts.delete')
    <span>Удаление недоступно</span>
@endcannot

@canany(['app.posts.edit', 'app.posts.delete'])
    <div class="actions">…</div>
@endcanany
```

## Директивы AzGuard

### Проверка права — `@azcan`

```blade
@azcan('app.documents.view')
    {{ $doc->title }}
@elseazcan('app.documents.preview')
    {{ Str::limit($doc->title, 20) }}
@endazcan

@unlessazcan('app.posts.delete')
    <p>Удаление недоступно</p>
@endunlessazcan
```

### Проверка роли — `@azrole`

`@azrole` принимает имя роли (строку). Имя выводится из класса роли:
`EditorRole` → `editor`, `AdminRole` → `admin`.

```blade
@azrole('app:editor')
    <nav>Меню редактора</nav>
@endazrole

@azrole('app:admin')
    <a href="/admin">Панель администратора</a>
@endazrole
```

### Проверка прямого гранта — `@azdirect`

```blade
@azdirect('app.reports.export')
    <button>Экспорт</button>
@endazdirect
```

## Использование с enum-кейсами

В `@azcan` можно передавать enum-кейс — панель подставит префикс автоматически:

```blade
@azcan(DocumentsPermission::View)
    {{ $doc->title }}
@endazcan

{{-- Для компонентов Livewire --}}
@php($canEdit = auth()->user()?->hasPermission(DocumentsPermission::Edit))
<livewire:document-editor :editable="$canEdit" />
```

## Проверка с моделью (через Policy)

```blade
@can('update', $post)
    <button type="submit">Сохранить</button>
@endcan
```

::: tip
`@can` + модель маршрутизируется через Laravel Policy, которая может использовать `hasPermission()` внутри.
:::

→ [Политики и Gate](/ru/best-practices/policies-and-gates) · [Права на фронтенде](/ru/basic-usage/abilities-frontend)

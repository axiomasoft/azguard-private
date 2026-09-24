# Perplexity capture — P3 — 2026-09-23

**Tool:** `perplexity.search` (ordinary search)
**Capture kind:** response text returned to the design session
**Normative status:** non-normative retrieval lead; load-bearing claims require direct primary-source or repository verification.

## Query

Use only primary sources and provide a concise evidence ledger with URLs, no quotations. Laravel 13 Eloquent custom model subclasses: table, connection, events, casts and global scopes; difference from DB::table query builder; Filament 5 Resource getModel override. Focus on a package that exposes configurable model classes and must preserve subclass semantics.

## Response

Для пакета с настраиваемым классом модели правило простое: все доменные операции должны начинаться с фактического Eloquent model class, а не с имени таблицы. Иначе пользовательские подклассы теряют контракт: таблицу, соединение, касты, lifecycle events, глобальные scopes, relation/accessor/mutator-логику и policy-интеграции.

## Рекомендуемый контракт

Принимайте и храните конфигурацию как class-string<Model>:
```
use Illuminate\Database\Eloquent\Model; /** @var class-string<Model> */ protected string $modelClass;
```
Стройте запросы и создавайте записи через этот класс:
```
$modelClass = config('vendor-package.models.record'); $query = $modelClass::query(); // Eloquent\Builder конкретного subclass $model = new $modelClass(); // экземпляр конкретного subclass $record = $modelClass::create($data); // casts, events, table, connection и т.д.
```
Не заменяйте это на:
```
DB::table('records');
```
И не извлекайте/кешируйте только $table или $connection: они являются лишь частью семантики модели, а не её заменой.

## Что сохраняет subclass

Laravel 13 описывает Eloquent как ORM, в котором модель соответствует таблице и используется также для insert/update/delete. Модель может явно задавать таблицу, ключевые характеристики, timestamp-поведение и соединение через attributes; Eloquent query гидратирует Eloquent models и возвращает Illuminate\Database\Eloquent\Collection.
Глобальные scopes применяются ко всем запросам модели; события модели охватывают lifecycle от retrieved и creating до deleted, restored и других. При mass update через Eloquent сами обновляемые модели не извлекаются, поэтому instance-события saving, saved, updating, updated не вызываются — это отдельное важное исключение даже при использовании Eloquent.

## Eloquent против DB::table()

DB::table('…') — это Query Builder по указанной строке таблицы. get() возвращает базовую Laravel collection, содержащую stdClass; это не экземпляры пользовательского model subclass. Поэтому query builder подходит для сознательно “raw/table-level” задач: отчётных агрегаций, ETL, миграционных/служебных bulk-операций, SQL-centric joins. Он не является семантически эквивалентной заменой модели.
Практическое следствие для extensible package:
```
// Правильно: все model semantics принадлежат выбранному subclass. public function records(): \Illuminate\Database\Eloquent\Builder { /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */ $model = config('acme.records.model'); return $model::query(); } // Неправильно для доменного API пакета: public function records(): \Illuminate\Database\Query\Builder { return DB::table('acme_records'); }
```
Даже вариант DB::connection($model->getConnectionName())->table($model->getTable()) сохраняет лишь table/connection. Он всё ещё обходит hydration, casts, global scopes, model events, relationships и остальную логику subclass.

## Безопасный дизайн пакета

- Одна точка разрешения класса. Создайте modelClass(): class-string<Model> в сервисе/контракте пакета. Валидируйте, что настроенный класс существует и is_subclass_of($class, Model::class).
- Возвращайте Eloquent builder. Публичные методы чтения должны возвращать Illuminate\Database\Eloquent\Builder, созданный через $class::query(). Это сохраняет scopes и позволяет потребителю добавлять свои Eloquent constraints.
- Создавайте через модель. Используйте new $class, $class::create(), $instance->fill()->save() или relationship methods конкретной модели. Это сохраняет касты, guarded/fillable, timestamps и lifecycle.
- Не подменяйте модель базовым package model. Если пользователь передал App\Models\CustomRecord extends Vendor\Package\Models\Record, не создавайте new Record, не вызывайте Record::query(), не хардкодьте Record::class в relation type hints и не конвертируйте результат в базовый класс.
- Не рассчитывайте на “случайную” совместимость bulk операций. Если package предоставляет ->update() или ->delete() на builder, документируйте, что model instances не гидратируются; следовательно, instance events и per-model hooks могут не сработать. Если hooks — инвариант, загружайте модели и выполняйте save()/delete() по экземплярам либо предоставьте отдельный explicit bulk API.
- Сделайте bypass явным. Если пакет действительно нуждается в table-level оптимизации, выделите метод вроде rawTableQuery() или bulkUpdateWithoutModelEvents(), а не используйте DB::table() внутри обычных CRUD-paths незаметно для интегратора.

## Filament 5

Filament Resources предназначены для CRUD-интерфейсов над Eloquent models; Filament начинает запросы ресурса через getEloquentQuery() и по умолчанию соблюдает зарегистрированные global scopes модели. Следовательно, Resource должен разрешать тот же configurable subclass, что и пакетный доменный слой.
Если конфигурация модели динамическая или package base resource не должен фиксировать $model, override getModel() — корректная точка выбора класса:
```
namespace App\Filament\Resources; use Filament\Resources\Resource; use Illuminate\Database\Eloquent\Model; final class RecordResource extends Resource { public static function getModel(): string { /** @var class-string<Model> $model */ $model = config('acme.records.model'); if (! is_a($model, Model::class, true)) { throw new \LogicException( 'acme.records.model must be an Eloquent model class.' ); } return $model; } }
```
Затем не переопределяйте getEloquentQuery() через DB::table(). Если требуется добавить ограничения ресурса, расширяйте родительский Eloquent query:
```
use Illuminate\Database\Eloquent\Builder; public static function getEloquentQuery(): Builder { return parent::getEloquentQuery() ->where('is_visible_in_admin', true); }
```
Так Filament использует выбранный getModel() class, а вместе с ним — table/connection/casts/scopes этой модели. Filament документирует getEloquentQuery() как общий старт всех resource model queries и отдельно указывает, что global scopes по умолчанию соблюдаются; их снятие должно быть явным через withoutGlobalScopes() или withoutGlobalScopes([...]).

## Evidence ledger

Sources:
1. [Laravel Eloquent 13](https://laravel.com/framework/docs/13.x/eloquent)
2. [Laravel Query Builder 13](https://laravel.com/framework/docs/13.x/queries)
3. [Filament 5 Resources overview](https://filamentphp.com/docs/5.x/resources/overview)
4. [Laravel 13 Model API](https://api.laravel.com/docs/13.x/Illuminate/Database/Eloquent/Model.html)


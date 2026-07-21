# Filament v5 — справочник компонентов (выжимка)

Namespaces см. в SKILL.md — они главный источник ошибок. Здесь — частые компоненты.

## Поля форм (`Filament\Forms\Components\`)

```php
use Filament\Forms\Components\{
    TextInput, Select, Textarea, Toggle, DateTimePicker,
    FileUpload, RichEditor, Repeater
};

TextInput::make('name')->required()->maxLength(255);
TextInput::make('email')->email()->unique(ignoreRecord: true);
TextInput::make('price')->numeric()->prefix('€');

Select::make('category_id')
    ->relationship('category', 'name')   // BelongsTo; BelongsToSelect не существует
    ->searchable()
    ->preload()
    ->createOptionForm([...]);           // inline-создание

DateTimePicker::make('published_at')->timezone('Europe/Helsinki');

FileUpload::make('avatar')
    ->image()->disk('public')->directory('avatars')
    ->visibility('public');              // по умолчанию private!

RichEditor::make('content')->toolbarButtons(['bold', 'italic', 'link', 'bulletList']);

Repeater::make('contacts')               // ->schema(), не ->fields()
    ->relationship()                      // inline HasMany по имени поля
    ->schema([TextInput::make('phone'), TextInput::make('label')])
    ->collapsible();
```

## Layout (`Filament\Schemas\Components\`) — не Forms\Components!

```php
use Filament\Schemas\Components\{Section, Grid, Tabs};

Section::make('Main Info')->schema([...])->columns(2);
Grid::make(2)->schema([...]);            // детям — ->columnSpan()/->columnSpanFull()
Tabs::make()->tabs([
    Tabs\Tab::make('General')->schema([...]),
    Tabs\Tab::make('SEO')->schema([...]),
]);
```

## Реактивность (`Filament\Schemas\Components\Utilities\Get|Set`)

```php
use Filament\Schemas\Components\Utilities\{Get, Set};
use Illuminate\Support\Str;

Select::make('type')->options(CompanyType::class)->required()->live(),
TextInput::make('company_name')
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

TextInput::make('title')
    ->live(onBlur: true)   // не на каждый keystroke
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
```

## Колонки и фильтры таблиц

```php
use Filament\Tables\Columns\{TextColumn, ImageColumn, IconColumn};
use Filament\Tables\Filters\{SelectFilter, TernaryFilter, Filter};
use Illuminate\Database\Eloquent\Builder;

TextColumn::make('name')->searchable()->sortable();
TextColumn::make('price')->money('EUR')->sortable();
TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
    'active' => 'success',
    'inactive' => 'danger',
});
TextColumn::make('created_at')->dateTime()->since();
TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}");
ImageColumn::make('avatar')->circular();
IconColumn::make('is_featured')->boolean();

SelectFilter::make('status')->options(Status::class);          // из Enum
SelectFilter::make('author')->relationship('author', 'name');
TernaryFilter::make('is_active');
Filter::make('verified')
    ->query(fn (Builder $q) => $q->whereNotNull('email_verified_at'));
```

## Actions (`Filament\Actions\` — единственный namespace)

```php
use Filament\Actions\{Action, EditAction, DeleteAction, BulkActionGroup, DeleteBulkAction};

Action::make('approve')
    ->color('success')
    ->icon(Heroicon::Check)
    ->requiresConfirmation()
    ->action(fn (Model $record) => $record->approve());

Action::make('sendEmail')                 // модалка с формой — ->schema()
    ->schema([
        TextInput::make('subject')->required(),
        Textarea::make('body')->required(),
    ])
    ->action(function (array $data, Model $record) {
        Mail::to($record->email)->send(new CustomMail($data));
    });

ExportAction::make()->exporter(UserExporter::class);
ImportAction::make()->importer(UserImporter::class);
```

## Widgets

```php
// Stats Overview
protected function getStats(): array
{
    return [
        Stat::make('Total Users', User::count())
            ->description('All registered users')
            ->color('success')
            ->chart([7, 3, 4, 5, 6, 3, 5, 3]),
    ];
}

// Chart
protected function getData(): array
{
    return [
        'datasets' => [['label' => 'Revenue', 'data' => [100, 200, 150, 300]]],
        'labels' => ['Jan', 'Feb', 'Mar', 'Apr'],
    ];
}
```

Регистрация — `->widgets([...])` в PanelServiceProvider или `getHeaderWidgets()`/`getFooterWidgets()` страницы.

## Infolist (view-страницы)

```php
use Filament\Infolists\Components\{TextEntry, ImageEntry};
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

public static function infolist(Schema $schema): Schema
{
    return $schema->components([
        Section::make('Profile')->schema([
            TextEntry::make('name'),
            TextEntry::make('email'),
            ImageEntry::make('avatar')->circular(),
        ])->columns(2),
    ]);
}
```

## Notifications

```php
use Filament\Notifications\Notification;

Notification::make()->title('Saved!')->success()->send();
Notification::make()->title('New order')->sendToDatabase(auth()->user());
```

## Тесты (pest-plugin-livewire)

```php
use function Pest\Livewire\livewire;

$this->actingAs(User::factory()->create());   // всегда перед панелью

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name);

livewire(CreateUser::class)
    ->fillForm(['name' => 'Test', 'email' => 'test@example.com'])
    ->call('create')
    ->assertHasNoFormErrors()
    ->assertRedirect();

livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')                 // не 'create'; edit НЕ редиректит
    ->assertHasNoFormErrors();

// Table actions
use Filament\Actions\Testing\TestAction;
livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), ['role' => 'admin'])
    ->assertNotified();
```

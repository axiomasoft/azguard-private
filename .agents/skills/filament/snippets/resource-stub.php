<?php

// Source: azguard/filament (anonymized), extended to the full v5 layout

declare(strict_types=1);

namespace App\Filament\Resources\Examples;

use App\Filament\Resources\Examples\Pages;
use App\Filament\Resources\Examples\Schemas\ExampleForm;
use App\Filament\Resources\Examples\Tables\ExamplesTable;
use App\Models\Example;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ExampleResource extends Resource
{
    protected static ?string $model = Example::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::RectangleStack;

    protected static string | UnitEnum | null $navigationGroup = 'Admin';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ExampleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExamplesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // RelationManagers\ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExamples::route('/'),
            'create' => Pages\CreateExample::route('/create'),
            'edit' => Pages\EditExample::route('/{record}/edit'),
        ];
    }
}

// --- Schemas/ExampleForm.php -------------------------------------------------

namespace App\Filament\Resources\Examples\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class ExampleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('is_active')->default(true),
        ]);
    }
}

// --- Tables/ExamplesTable.php ------------------------------------------------

namespace App\Filament\Resources\Examples\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class ExamplesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}

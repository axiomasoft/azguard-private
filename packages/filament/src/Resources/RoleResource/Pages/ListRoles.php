<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\Pages;

use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\RoleResource;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;

/**
 * The code roles of one managed panel, chosen in the filter; a row opens the role. The table has no action.
 *
 * @internal
 */
final class ListRoles extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = RoleResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $selector = TargetSelector::current();

        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('key')->label('Key')->searchable(false),
                TextColumn::make('label')->label('Label'),
                TextColumn::make('class')->label('Class')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('grantable')->label('Granted by hand')->boolean(),
                IconColumn::make('automatic')->label('Automatic')->boolean(),
                IconColumn::make('super_admin')->label('Superadmin')->boolean(),
                TextColumn::make('contexts')->label('Assignment scopes')->badge(),
            ])
            ->filters([
                Filter::make('target')->schema([
                    Select::make('panel')->label('Panel')->options($selector->panels())->default($selector->defaultPanel())->selectablePlaceholder(false),
                ]),
            ])
            ->deferFilters(false)
            ->recordUrl(fn (array $record): string => ViewRole::getUrl(['panel' => $this->panel(), 'role' => $record['key']]))
            ->paginated(false);
    }

    /** The panel of the filter, checked again; the first managed panel when none is chosen. */
    private function panel(): ?string
    {
        $panel = $this->tableFilters['target']['panel'] ?? null;

        return is_string($panel) && $panel !== '' ? $panel : TargetSelector::current()->defaultPanel();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $panel = $this->panel();

        if ($panel === null) {
            return [];
        }
        $access = TargetSelector::current()->definitions($panel);
        $permissions = RoleResource::permissionsOf($access->schema());
        $rows = [];

        foreach ($access->roles()->all() as $role) {
            $rows[$role->key->key()] = RoleResource::row($role, $permissions);
        }

        return $rows;
    }
}

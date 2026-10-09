<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\PermissionResource\Pages;

use AzGuard\Changes\ChangeResult;
use AzGuard\Changes\PermissionDetails;
use AzGuard\Contracts\PanelAccess;
use AzGuard\Exceptions\AzGuardException;
use AzGuard\Facades\AzGuard;
use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\PermissionResource;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The permissions of one managed panel with dynamic permissions and, where the editor chooses it, one tenant. The panel
 * and the tenant are filter values from the client: every read and every write checks them again.
 *
 * @internal
 */
final class ListPermissions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PermissionResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $selector = self::selector();

        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('name')->label('Permission'),
                TextColumn::make('label')->label('Label'),
                TextColumn::make('domain')->label('Domain')->badge()->color('gray'),
                TextColumn::make('group')->label('Group')->placeholder('—'),
                TextColumn::make('authority')->label('Authority')->badge()
                    ->formatStateUsing(static fn (string $state): string => $state === 'policy' ? 'decided by the policy' : 'granted')
                    ->color(static fn (string $state): string => $state === 'policy' ? 'warning' : 'gray')
                    ->tooltip(static fn (array $record): ?string => $record['decided_by']),
                IconColumn::make('dynamic')->label('Dynamic')->boolean(),
                TextColumn::make('description')->label('Description')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('target')->schema([
                    Select::make('panel')->label('Panel')
                        ->options($selector->panels())
                        ->default($selector->defaultPanel())
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(static fn (Set $set): mixed => $set('tenant', null)),
                    Select::make('tenant')->label('Tenant')
                        ->searchable()
                        ->getSearchResultsUsing(static fn (Get $get, ?string $search): array => self::selector()->searchTenants($get('panel'), (string) $search))
                        ->getOptionLabelUsing(static fn (Get $get, ?string $value): ?string => self::selector()->tenantLabel($get('panel'), $value))
                        ->visible(static fn (Get $get): bool => self::selector()->choosesTenant($get('panel'))),
                ]),
            ])
            ->deferFilters(false)
            ->headerActions([
                Action::make('create')->label('New permission')
                    ->visible(fn (): bool => PermissionResource::can('create') && $this->chosen())
                    ->schema([
                        TextInput::make('name')->label('Name')->required(),
                        TextInput::make('label')->label('Label'),
                        TextInput::make('group')->label('Group'),
                    ])
                    ->action(fn (array $data) => $this->write('create', static fn (PanelAccess $access): ChangeResult => $access->permissions()->create(
                        (string) $data['name'],
                        self::text($data['label'] ?? null),
                        self::text($data['group'] ?? null),
                    ))),
            ])
            ->recordActions([
                Action::make('edit')->label('Edit')
                    ->visible(static fn (array $record): bool => $record['dynamic'] && PermissionResource::can('update'))
                    ->fillForm(static fn (array $record): array => ['label' => $record['label'], 'group' => $record['group']])
                    ->schema([
                        TextInput::make('label')->label('Label'),
                        TextInput::make('group')->label('Group'),
                    ])
                    ->action(fn (array $record, array $data) => $this->write('update', static fn (PanelAccess $access): ChangeResult => $access->permissions()->update(
                        $record['name'],
                        new PermissionDetails(self::text($data['label'] ?? null), self::text($data['group'] ?? null), $record['description']),
                    ))),
                Action::make('delete')->label('Delete')->color('danger')->requiresConfirmation()
                    ->modalDescription('The permission is deleted with every grant of it in this tenant.')
                    ->visible(static fn (array $record): bool => $record['dynamic'] && PermissionResource::can('delete'))
                    ->action(fn (array $record) => $this->write('delete', static fn (PanelAccess $access): ChangeResult => $access->permissions()->delete($record['name']))),
            ])
            ->paginated(false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        if (! $this->chosen()) {
            return [];
        }
        $rows = [];

        foreach ($this->target()->permissions()->all() as $permission) {
            $rows[$permission->key->local()] = PermissionResource::row($permission);
        }

        return $rows;
    }

    /**
     * Changes the target as the user who edits, after the permission of the editor and the target are checked again.
     *
     * @param  Closure(PanelAccess): mixed  $change
     */
    private function write(string $ability, Closure $change): void
    {
        abort_unless(PermissionResource::can($ability), 403);
        $access = $this->target();
        $user = Filament::auth()->user();
        abort_unless($user instanceof Model, 403);

        try {
            AzGuard::actingAs($user, static fn () => $change($access));
        } catch (AzGuardException $error) {
            Notification::make()->danger()->title('The permission was not changed')->body($error->getMessage())->send();

            return;
        }
        Notification::make()->success()->title('Saved')->send();
        $this->resetPage($this->getTablePaginationPageName());
        $this->flushCachedTableRecords();
    }

    /** Whether the filter names a panel, and a tenant where the editor chooses one. */
    private function chosen(): bool
    {
        $panel = $this->filter('panel');

        return $panel !== null && (! self::selector()->choosesTenant($panel) || $this->filter('tenant') !== null);
    }

    private function target(): PanelAccess
    {
        return self::selector()->access($this->filter('panel'), $this->filter('tenant'));
    }

    private function filter(string $name): ?string
    {
        $value = $this->tableFilters['target'][$name] ?? null;

        if ($name === 'panel' && ($value === null || $value === '')) {
            return self::selector()->defaultPanel();
        }

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private static function selector(): TargetSelector
    {
        return TargetSelector::current(PermissionResource::keepsDynamicPermissions(...));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

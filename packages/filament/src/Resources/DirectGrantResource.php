<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources;

use AzGuard\AzGuardManager;
use AzGuard\Filament\Resources\DirectGrantResource\Pages\CreateDirectGrant;
use AzGuard\Filament\Resources\DirectGrantResource\Pages\ListDirectGrants;
use AzGuard\Models\DirectGrant;
use AzGuard\Registry\Contracts\PermissionCatalog;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Override;
use UnitEnum;

/**
 * Filament Resource for viewing, creating and revoking Direct Grants.
 *
 * Creation form:
 *  1. Select a user (searchable)
 *  2. Select a panel from the registered panels
 *  3. Select a permission from the PermissionCatalog (reactively filtered by panel)
 *  4. Expiry date (optional — no expiry)
 */
final class DirectGrantResource extends Resource
{
    protected static ?string $model = DirectGrant::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'AzGuard';

    protected static ?string $label = 'Direct Grant';

    protected static ?string $pluralLabel = 'Direct Grants';

    // ─── Form ─────────────────────────────────────────────────────────────────

    #[Override]
    public static function form(Schema $schema): Schema
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $labelColumn = config('az-guard-filament.user_label_column', 'name');

        return $schema->components([

            // ── 1. User ──────────────────────────────────────────────────────
            Select::make('grantable_id')
                ->label('User')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(
                    fn (string $search) => $userModel::where($labelColumn, 'like', "%{$search}%")
                        ->limit(50)
                        ->pluck($labelColumn, 'id')
                        ->toArray(),
                )
                ->getOptionLabelUsing(
                    fn ($value) => $userModel::find($value)?->{$labelColumn} ?? "#{$value}",
                )
                ->columnSpan('full'),

            // ── 2. Panel ─────────────────────────────────────────────────────
            Select::make('panel_id')
                ->label('Panel')
                ->required()
                ->options(fn () => collect(app(AzGuardManager::class)->getPanels())
                    ->mapWithKeys(fn ($panel, $id): array => [$id => $id])
                    ->toArray(),
                )
                ->live()
                ->afterStateUpdated(fn ($set) => $set('permission_key', null)),

            // ── 3. Permission (reactive by panel) ────────────────────────────
            Select::make('permission_key')
                ->label('Permission')
                ->required()
                ->options(function (Get $get): array {
                    $panelId = $get('panel_id');

                    if (! $panelId) {
                        return [];
                    }

                    /** @var PermissionCatalog $catalog */
                    $catalog = app(PermissionCatalog::class);
                    $options = [];

                    foreach ($catalog->groups($panelId) as $group => $definitions) {
                        foreach ($definitions as $def) {
                            $label = ($def->label() ?? $def->key());
                            $options[$group][$def->key()] = $label;
                        }
                    }

                    return $options;
                })
                ->searchable()
                ->disabled(fn (Get $get): bool => ! $get('panel_id'))
                ->helperText(fn (Get $get): string => $get('panel_id')
                    ? ''
                    : 'Select a panel first.',
                ),

            // ── 4. Expiry date ───────────────────────────────────────────────
            DateTimePicker::make('expires_at')
                ->label('Valid until')
                ->nullable()
                ->helperText('Leave empty for a grant that never expires.')
                ->minDate(now()->addMinute()),

        ])->columns(2);
    }

    // ─── Table ────────────────────────────────────────────────────────────────

    #[Override]
    public static function table(Table $table): Table
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $labelColumn = config('az-guard-filament.user_label_column', 'name');

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('grantable'))
            ->columns([
                TextColumn::make('grantable_id')
                    ->label('User')
                    ->formatStateUsing(function (string $state, DirectGrant $record) use ($userModel, $labelColumn): string {
                        if ($record->grantable_type !== $userModel) {
                            return $record->grantable_type.'#'.$state;
                        }

                        return $record->grantable?->{$labelColumn} ?? "#{$state}";
                    })
                    ->searchable(),

                TextColumn::make('panel_id')
                    ->label('Panel')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('permission_key')
                    ->label('Permission')
                    ->searchable(),

                TextColumn::make('expires_at')
                    ->label('Valid until')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Never')
                    ->color(fn (DirectGrant $record): string => $record->isExpired() ? 'danger' : 'success')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Issued')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('panel_id')
                    ->label('Panel')
                    ->options(fn () => DirectGrant::query()->distinct()->pluck('panel_id', 'panel_id')),

                Filter::make('active')
                    ->label('Active only')
                    ->query(fn ($query) => $query->where(
                        fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()),
                    )),
            ])
            ->actions([
                DeleteAction::make()->label('Revoke'),
            ])
            ->bulkActions([
                DeleteBulkAction::make()->label('Revoke selected'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // ─── Pages ────────────────────────────────────────────────────────────────

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDirectGrants::route('/'),
            'create' => CreateDirectGrant::route('/create'),
        ];
    }
}

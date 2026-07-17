<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * Relation Manager: users of a DB role.
 *
 * Allows assigning / revoking the role for users.
 * Uses the morph relation via Role::users().
 */
final class RoleUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users';

    #[Override]
    public function form(Schema $schema): Schema
    {
        $userModel = config('auth.providers.users.model', 'App\\Models\\User');
        $labelColumn = config('az-guard-filament.user_label_column', 'name');

        return $schema->components([
            Select::make('id')
                ->label('User')
                ->options(fn () => $userModel::query()->pluck($labelColumn, 'id'))
                ->searchable()
                ->required(),
        ]);
    }

    #[Override]
    public function table(Table $table): Table
    {
        $labelColumn = config('az-guard-filament.user_label_column', 'name');

        return $table
            ->recordTitleAttribute($labelColumn)
            ->columns([
                TextColumn::make('id')->label('ID')->width('60px'),
                TextColumn::make($labelColumn)->label('User')->searchable(),
                TextColumn::make('email')->label('Email')->searchable()->toggleable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Assign role')
                    ->preloadRecordSelect(),
            ])
            ->actions([
                DetachAction::make()->label('Revoke'),
            ])
            ->bulkActions([
                DetachBulkAction::make()->label('Revoke selected'),
            ]);
    }
}

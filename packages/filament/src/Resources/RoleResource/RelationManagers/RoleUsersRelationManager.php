<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\RelationManagers;

use AzGuard\Models\Role;
use AzGuard\Registry\Resolver\PermissionStateRevision;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Override;
use RuntimeException;

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
                    ->preloadRecordSelect()
                    ->using(function (AttachAction $action): void {
                        $record = $action->getRecord();

                        if (! $record instanceof Model) {
                            throw new RuntimeException('AzGuard could not resolve the selected user.');
                        }

                        $this->assignRoleTo($record);
                    }),
            ])
            ->actions([
                DetachAction::make()->label('Revoke')->using(function (Model $record): void {
                    $this->removeRoleFrom($record);
                }),
            ])
            ->bulkActions([
                DetachBulkAction::make()->label('Revoke selected')->using(function (EloquentCollection|Collection|LazyCollection $records): void {
                    app(PermissionStateRevision::class)->mutate(function () use ($records): array {
                        foreach ($records as $record) {
                            $this->removeRoleFrom($record);
                        }

                        return [null, false];
                    });
                }),
            ]);
    }

    private function assignRoleTo(Model $user): void
    {
        if (! method_exists($user, 'assignRole')) {
            throw new RuntimeException('AzGuard user model must provide assignRole().');
        }

        $user->assignRole($this->ownerRole());
    }

    private function removeRoleFrom(Model $user): void
    {
        if (! method_exists($user, 'removeRole')) {
            throw new RuntimeException('AzGuard user model must provide removeRole().');
        }

        $user->removeRole($this->ownerRole());
    }

    private function ownerRole(): Role
    {
        $role = $this->getOwnerRecord();
        assert($role instanceof Role);

        return $role;
    }
}

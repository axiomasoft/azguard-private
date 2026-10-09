<?php

declare(strict_types=1);

namespace AzGuard\Filament\Resources\RoleResource\Pages;

use AzGuard\Filament\Editors\TargetSelector;
use AzGuard\Filament\Resources\RoleResource;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;

/**
 * One code role, read-only: the panel and the role come from the route and cannot be changed by the client, and the
 * page has no form, property or method that writes.
 *
 * @internal
 *
 * @phpstan-import-type Row from RoleResource
 */
final class ViewRole extends Page
{
    protected static string $resource = RoleResource::class;

    #[Locked]
    public string $panel = '';

    #[Locked]
    public string $role = '';

    public function mount(string $panel, string $role): void
    {
        abort_unless(RoleResource::can('view'), 403);
        $this->panel = $panel;
        $this->role = $role;
        $this->row();
    }

    public function getTitle(): string
    {
        return $this->row()['label'];
    }

    public function content(Schema $schema): Schema
    {
        $authority = static fn (?string $state): string => match ($state) {
            'policy' => 'decided by the policy',
            'grants' => 'granted',
            default => 'pattern',
        };

        return $schema->constantState($this->row())->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('key')->label('Key'),
                TextEntry::make('class')->label('Class'),
                IconEntry::make('grantable')->label('Granted by hand')->boolean(),
                IconEntry::make('automatic')->label('Automatic')->boolean(),
                IconEntry::make('super_admin')->label('Superadmin')->boolean(),
                IconEntry::make('scope_required')->label('Needs an assignment scope')->boolean(),
                TextEntry::make('contexts')->label('Assignment scopes')->badge()->placeholder('—'),
                TextEntry::make('filters')->label('Filters')->listWithLineBreaks()->placeholder('—'),
            ]),
            RepeatableEntry::make('permissions')->label('Permissions')->columns(2)->schema([
                TextEntry::make('name')->label('Permission'),
                TextEntry::make('authority')->label('Authority')->badge()
                    ->formatStateUsing($authority)
                    ->color(static fn (?string $state): string => $state === 'policy' ? 'warning' : 'gray'),
            ]),
        ]);
    }

    /**
     * The role, found again in the checked panel on every render.
     *
     * @return Row
     */
    private function row(): array
    {
        $access = TargetSelector::current()->definitions($this->panel);
        $role = $access->roles()->find($this->role);

        abort_if($role === null, 404);

        return RoleResource::row($role, RoleResource::permissionsOf($access->schema()));
    }
}

<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Crm\Filament\Pages;

use AzGuard\Filament\Authorization\FilamentGate;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientCalled;
use AzGuard\Tests\Fixtures\Crm\Filament\ClientResource;
use AzGuard\Tests\Fixtures\Crm\Models\Client;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;

/** The card of a client with the action that records a call, a write of the client allowed by `clients.update`. */
final class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('call')
                ->authorize(static fn (Client $record): Response => FilamentGate::resource(ClientResource::class, 'update', $record))
                ->action(static function (Client $record): void {
                    DB::table('client_calls')->insert(['client_id' => $record->getKey(), 'user_id' => auth()->id()]);
                    event(new ClientCalled((int) $record->getKey()));
                }),
        ];
    }
}

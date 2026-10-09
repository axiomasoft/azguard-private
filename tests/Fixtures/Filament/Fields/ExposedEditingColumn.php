<?php

declare(strict_types=1);

namespace AzGuard\Tests\Fixtures\Filament\Fields;

use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Tables\Columns\TextInputColumn;
use Illuminate\Database\Eloquent\Model;

final class ExposedEditingColumn extends TextInputColumn
{
    #[ExposedLivewireMethod]
    public function replaceNumber(string $value): void
    {
        $record = $this->getRecord();

        if ($record instanceof Model) {
            $record->update(['number' => $value]);
        }
    }
}

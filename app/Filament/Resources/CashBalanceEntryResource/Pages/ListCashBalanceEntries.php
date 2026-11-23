<?php

namespace App\Filament\Resources\CashBalanceEntryResource\Pages;

use App\Filament\Resources\CashBalanceEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListCashBalanceEntries extends ListRecords
{
    protected static string $resource = CashBalanceEntryResource::class;

    public function hydrate(): void
    {
        abort_unless(CashBalanceEntryResource::canViewAny(), 403);
    }
}

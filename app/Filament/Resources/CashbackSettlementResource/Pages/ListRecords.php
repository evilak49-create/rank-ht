<?php

namespace App\Filament\Resources\CashbackSettlementResource\Pages;

use App\Filament\Resources\CashbackSettlementResource;
use Filament\Resources\Pages\ListRecords as BaseListRecords;

class ListRecords extends BaseListRecords
{
    protected static string $resource = CashbackSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return [CashbackSettlementResource::configurationAction()];
    }
}

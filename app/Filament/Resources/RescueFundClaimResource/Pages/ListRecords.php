<?php

namespace App\Filament\Resources\RescueFundClaimResource\Pages;

use App\Filament\Resources\RescueFundClaimResource;
use Filament\Resources\Pages\ListRecords as BaseListRecords;

class ListRecords extends BaseListRecords
{
    protected static string $resource = RescueFundClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [RescueFundClaimResource::configurationAction()];
    }
}

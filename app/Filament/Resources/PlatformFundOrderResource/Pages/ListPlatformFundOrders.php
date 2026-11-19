<?php

namespace App\Filament\Resources\PlatformFundOrderResource\Pages;

use App\Filament\Resources\PlatformFundOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformFundOrders extends ListRecords
{
    protected static string $resource = PlatformFundOrderResource::class;

    public function hydrate(): void
    {
        abort_unless(PlatformFundOrderResource::canViewAny(), 403);
    }
}

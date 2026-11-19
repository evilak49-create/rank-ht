<?php

namespace App\Filament\Resources\ShopOrderResource\Pages;

use App\Filament\Resources\ShopOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListShopOrders extends ListRecords
{
    protected static string $resource = ShopOrderResource::class;

    public function hydrate(): void
    {
        abort_unless(ShopOrderResource::canViewAny(), 403);
    }
}

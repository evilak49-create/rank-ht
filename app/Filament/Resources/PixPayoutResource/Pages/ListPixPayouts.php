<?php

namespace App\Filament\Resources\PixPayoutResource\Pages;

use App\Filament\Resources\PixPayoutResource;
use Filament\Resources\Pages\ListRecords;

class ListPixPayouts extends ListRecords
{
    protected static string $resource = PixPayoutResource::class;

    public function hydrate(): void
    {
        abort_unless(PixPayoutResource::canViewAny(), 403);
    }
}

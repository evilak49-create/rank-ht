<?php

namespace App\Filament\Resources\PointQuoteResource\Pages;

use App\Filament\Resources\PointQuoteResource;
use Filament\Resources\Pages\ListRecords;

class ListPointQuotes extends ListRecords
{
    protected static string $resource = PointQuoteResource::class;

    public function hydrate(): void
    {
        abort_unless(PointQuoteResource::canViewAny(), 403);
    }
}

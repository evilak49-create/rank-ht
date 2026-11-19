<?php

namespace App\Filament\Resources\PointEntryResource\Pages;

use App\Filament\Resources\PointEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListPointEntries extends ListRecords
{
    protected static string $resource = PointEntryResource::class;

    public function hydrate(): void
    {
        abort_unless(PointEntryResource::canViewAny(), 403);
    }
}

<?php

namespace App\Filament\Resources\ProtectionTermResource\Pages;

use App\Filament\Resources\ProtectionTermResource;
use Filament\Resources\Pages\ListRecords;

class ListProtectionTerms extends ListRecords
{
    protected static string $resource = ProtectionTermResource::class;

    public function hydrate(): void
    {
        abort_unless(ProtectionTermResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [ProtectionTermResource::publishAction()];
    }
}

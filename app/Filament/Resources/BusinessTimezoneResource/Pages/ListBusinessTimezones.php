<?php

namespace App\Filament\Resources\BusinessTimezoneResource\Pages;

use App\Filament\Resources\BusinessTimezoneResource;
use Filament\Resources\Pages\ListRecords;

class ListBusinessTimezones extends ListRecords
{
    protected static string $resource = BusinessTimezoneResource::class;

    public function hydrate(): void
    {
        abort_unless(BusinessTimezoneResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [BusinessTimezoneResource::scheduleAction()];
    }
}

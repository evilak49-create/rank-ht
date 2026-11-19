<?php

namespace App\Filament\Resources\AnnouncementResource\Pages;

use App\Filament\Resources\AnnouncementResource;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    public function hydrate(): void
    {
        abort_unless(AnnouncementResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [AnnouncementResource::createAction()];
    }
}

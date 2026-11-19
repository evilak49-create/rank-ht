<?php

namespace App\Filament\Resources\SiteBannerResource\Pages;

use App\Filament\Resources\SiteBannerResource;
use Filament\Resources\Pages\ListRecords;

class ListSiteBanners extends ListRecords
{
    protected static string $resource = SiteBannerResource::class;

    public function hydrate(): void
    {
        abort_unless(SiteBannerResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [SiteBannerResource::createAction()];
    }
}

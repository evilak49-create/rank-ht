<?php

namespace App\Filament\Resources\SiteDomainResource\Pages;

use App\Filament\Resources\SiteDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSiteDomains extends ListRecords
{
    protected static string $resource = SiteDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('新增域名')];
    }
}

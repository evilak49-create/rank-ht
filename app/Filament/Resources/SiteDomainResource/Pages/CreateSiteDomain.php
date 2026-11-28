<?php

namespace App\Filament\Resources\SiteDomainResource\Pages;

use App\Filament\Resources\SiteDomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSiteDomain extends CreateRecord
{
    protected static string $resource = SiteDomainResource::class;

    protected static ?string $title = '新增域名';
}

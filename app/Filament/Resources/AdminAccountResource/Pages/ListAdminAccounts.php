<?php

namespace App\Filament\Resources\AdminAccountResource\Pages;

use App\Filament\Resources\AdminAccountResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminAccounts extends ListRecords
{
    protected static string $resource = AdminAccountResource::class;

    public function hydrate(): void
    {
        abort_unless(AdminAccountResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [AdminAccountResource::createAction()];
    }
}

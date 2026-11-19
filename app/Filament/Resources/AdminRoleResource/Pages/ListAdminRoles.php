<?php

namespace App\Filament\Resources\AdminRoleResource\Pages;

use App\Filament\Resources\AdminRoleResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminRoles extends ListRecords
{
    protected static string $resource = AdminRoleResource::class;

    public function hydrate(): void
    {
        abort_unless(AdminRoleResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [AdminRoleResource::createAction()];
    }
}

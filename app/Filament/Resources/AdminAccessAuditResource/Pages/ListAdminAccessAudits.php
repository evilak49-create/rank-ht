<?php

namespace App\Filament\Resources\AdminAccessAuditResource\Pages;

use App\Filament\Resources\AdminAccessAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListAdminAccessAudits extends ListRecords
{
    protected static string $resource = AdminAccessAuditResource::class;

    public function hydrate(): void
    {
        abort_unless(AdminAccessAuditResource::canViewAny(), 403);
    }
}

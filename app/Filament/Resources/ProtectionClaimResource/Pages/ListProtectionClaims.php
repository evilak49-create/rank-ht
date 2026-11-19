<?php

namespace App\Filament\Resources\ProtectionClaimResource\Pages;

use App\Filament\Resources\ProtectionClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListProtectionClaims extends ListRecords
{
    protected static string $resource = ProtectionClaimResource::class;

    public function hydrate(): void
    {
        abort_unless(ProtectionClaimResource::canViewAny(), 403);
    }
}

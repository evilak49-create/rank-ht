<?php

namespace App\Filament\Resources\ProtectionClaimResource\Pages;

use App\Filament\Resources\ProtectionClaimResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProtectionClaim extends ViewRecord
{
    protected static string $resource = ProtectionClaimResource::class;

    public function hydrate(): void
    {
        abort_unless(ProtectionClaimResource::canViewAny(), 403);
    }
}

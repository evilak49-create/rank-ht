<?php

namespace App\Filament\Resources\ProtectionEligibilityResource\Pages;

use App\Filament\Resources\ProtectionEligibilityResource;
use Filament\Resources\Pages\ListRecords;

class ListProtectionEligibility extends ListRecords
{
    protected static string $resource = ProtectionEligibilityResource::class;

    public function hydrate(): void
    {
        abort_unless(ProtectionEligibilityResource::canViewAny(), 403);
    }
}

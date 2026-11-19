<?php

namespace App\Filament\Resources\MemberCheckinResource\Pages;

use App\Filament\Resources\MemberCheckinResource;
use Filament\Resources\Pages\ListRecords;

class ListMemberCheckins extends ListRecords
{
    protected static string $resource = MemberCheckinResource::class;

    public function hydrate(): void
    {
        abort_unless(MemberCheckinResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [MemberCheckinResource::rulesAction()];
    }
}

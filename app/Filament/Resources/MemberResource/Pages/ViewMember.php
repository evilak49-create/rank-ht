<?php

namespace App\Filament\Resources\MemberResource\Pages;

use App\Filament\Resources\MemberResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    protected static ?string $title = '会员详情';

    public function hydrate(): void
    {
        abort_unless(MemberResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [MemberResource::cashLedgerAction(), MemberResource::bindingHistoryAction(), ...MemberResource::memberActions()];
    }
}

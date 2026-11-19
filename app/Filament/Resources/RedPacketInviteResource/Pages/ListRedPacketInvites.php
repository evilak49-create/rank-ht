<?php

namespace App\Filament\Resources\RedPacketInviteResource\Pages;

use App\Filament\Resources\RedPacketInviteResource;
use Filament\Resources\Pages\ListRecords;

class ListRedPacketInvites extends ListRecords
{
    protected static string $resource = RedPacketInviteResource::class;

    public function hydrate(): void
    {
        abort_unless(RedPacketInviteResource::canViewAny(), 403);
    }
}

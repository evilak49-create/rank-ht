<?php

namespace App\Filament\Resources\RedPacketRoundResource\Pages;

use App\Filament\Resources\RedPacketRoundResource;
use Filament\Resources\Pages\ListRecords;

class ListRedPacketRounds extends ListRecords
{
    protected static string $resource = RedPacketRoundResource::class;

    public function hydrate(): void
    {
        abort_unless(RedPacketRoundResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [RedPacketRoundResource::pageCopyAction(), RedPacketRoundResource::thresholdAction()];
    }
}

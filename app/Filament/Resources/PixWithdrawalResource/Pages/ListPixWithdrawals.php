<?php

namespace App\Filament\Resources\PixWithdrawalResource\Pages;

use App\Filament\Resources\PixWithdrawalResource;
use Filament\Resources\Pages\ListRecords;

class ListPixWithdrawals extends ListRecords
{
    protected static string $resource = PixWithdrawalResource::class;

    public function hydrate(): void
    {
        abort_unless(PixWithdrawalResource::canViewAny(), 403);
    }
}

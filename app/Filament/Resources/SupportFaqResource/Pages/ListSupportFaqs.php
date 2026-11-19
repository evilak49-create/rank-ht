<?php

namespace App\Filament\Resources\SupportFaqResource\Pages;

use App\Filament\Resources\SupportFaqResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportFaqs extends ListRecords
{
    protected static string $resource = SupportFaqResource::class;

    public function hydrate(): void
    {
        abort_unless(SupportFaqResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [SupportFaqResource::createAction()];
    }
}

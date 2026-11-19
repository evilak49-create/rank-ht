<?php

namespace App\Filament\Resources\PlatformReviewBatchResource\Pages;

use App\Filament\Resources\PlatformReviewBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformReviewBatches extends ListRecords
{
    protected static string $resource = PlatformReviewBatchResource::class;

    public function hydrate(): void
    {
        abort_unless(PlatformReviewBatchResource::canViewAny(), 403);
    }
}

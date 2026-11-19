<?php

namespace App\Filament\Resources\PlatformReviewResource\Pages;

use App\Filament\Resources\PlatformReviewResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformReviews extends ListRecords
{
    protected static string $resource = PlatformReviewResource::class;

    public function hydrate(): void
    {
        abort_unless(PlatformReviewResource::canViewAny(), 403);
    }
}

<?php

namespace App\Filament\Resources\HelpArticleResource\Pages;

use App\Filament\Resources\HelpArticleResource;
use Filament\Resources\Pages\ListRecords;

class ListHelpArticles extends ListRecords
{
    protected static string $resource = HelpArticleResource::class;

    public function hydrate(): void
    {
        abort_unless(HelpArticleResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [HelpArticleResource::createAction()];
    }
}

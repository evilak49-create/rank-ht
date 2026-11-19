<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HelpArticleResource\Pages\ListHelpArticles;

class HelpArticleResource extends SiteArticleResource
{
    protected static ?int $navigationSort = 30;

    protected static string $articleType = 'help';

    protected static ?string $pluralModelLabel = '帮助中心';

    public static function getPages(): array
    {
        return ['index' => ListHelpArticles::route('/')];
    }
}

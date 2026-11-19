<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages\ListAnnouncements;

class AnnouncementResource extends SiteArticleResource
{
    protected static string $articleType = 'announcement';

    protected static ?string $pluralModelLabel = '公告管理';

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/')];
    }
}

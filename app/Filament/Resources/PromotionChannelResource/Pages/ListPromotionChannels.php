<?php

namespace App\Filament\Resources\PromotionChannelResource\Pages;

use App\Filament\Resources\PromotionChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPromotionChannels extends ListRecords
{
    protected static string $resource = PromotionChannelResource::class;

    protected static ?string $title = '渠道列表';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('新增渠道')];
    }
}

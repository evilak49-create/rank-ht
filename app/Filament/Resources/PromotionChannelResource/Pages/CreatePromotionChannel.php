<?php

namespace App\Filament\Resources\PromotionChannelResource\Pages;

use App\Filament\Resources\PromotionChannelResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePromotionChannel extends CreateRecord
{
    protected static string $resource = PromotionChannelResource::class;

    protected static ?string $title = '新增渠道';
}

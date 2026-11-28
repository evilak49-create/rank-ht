<?php

namespace App\Filament\Resources\PromotionChannelResource\Pages;

use App\Filament\Resources\PromotionChannelResource;
use App\Models\PromotionChannel;
use App\Models\SiteDomain;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePromotionChannel extends CreateRecord
{
    protected static string $resource = PromotionChannelResource::class;

    protected static ?string $title = '新增渠道';

    protected function handleRecordCreation(array $data): Model
    {
        $host = SiteDomain::query()->where('purpose', SiteDomain::CHANNEL)->inRandomOrder()->value('host');
        if ($host === null) {
            throw ValidationException::withMessages(['data.name' => '请先在域名管理中添加用途为「渠道」的域名。']);
        }

        return PromotionChannel::create([...$data, 'promotion_host' => $host]);
    }
}

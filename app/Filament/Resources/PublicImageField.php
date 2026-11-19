<?php

namespace App\Filament\Resources;

use App\Storage\OssConfiguration;
use App\Storage\PublicImages;
use Filament\Forms\Components\FileUpload;

class PublicImageField
{
    public static function make(string $label): FileUpload
    {
        return FileUpload::make('image_upload')->label($label)->storeFiles(false)
            ->acceptedFileTypes(array_keys(PublicImages::MIMES))->maxSize(PublicImages::MAX_KIB)
            ->visible(fn (): bool => app(OssConfiguration::class)->active('public') !== null)
            ->helperText('上传图片后将使用新图片的公开地址。');
    }
}

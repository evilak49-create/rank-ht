<?php

namespace App\Filament\Resources;

use App\Storage\PublicImages;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

class PublicImageField
{
    public static function make(string $label): FileUpload
    {
        return FileUpload::make('image_upload')->label($label)->storeFiles(false)
            ->acceptedFileTypes(array_keys(PublicImages::MIMES))->maxSize(PublicImages::MAX_KIB);
    }

    public static function current(string $field, bool $retainSubmitted = false): TextInput
    {
        return TextInput::make($field)->label('当前图片地址')->readOnly()->dehydrated($retainSubmitted)
            ->visible(fn (Get $get): bool => filled($get($field)));
    }

    public static function remove(string $field): Toggle
    {
        return Toggle::make('remove_image')->label('移除当前图片')
            ->visible(fn (Get $get): bool => filled($get($field)));
    }

    public static function retain(array $data, string $field, ?string $current): array
    {
        $data[$field] = ($data['remove_image'] ?? false) ? null : $current;
        unset($data['remove_image']);

        return $data;
    }
}

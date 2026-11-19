<?php

namespace App\Filament\Resources;

use App\Content\Publishing;
use App\Models\Platform;
use App\Models\SiteArticle;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SiteTargetFields
{
    public static function make(bool $required): array
    {
        return [
            Select::make('target_type')->label('站内目标')->options(Publishing::TARGETS)->required($required)->live()
                ->afterStateUpdated(fn (Set $set) => $set('target_id', null)),
            Select::make('target_id')->label('目标记录')->searchable()
                ->visible(fn (Get $get): bool => in_array($get('target_type'), ['platform', 'help_article'], true))
                ->required(fn (Get $get): bool => in_array($get('target_type'), ['platform', 'help_article'], true))
                ->getSearchResultsUsing(function (string $search, Get $get): array {
                    [$query, $title] = self::records($get('target_type'));

                    return $query?->whereLike($title, '%'.$search.'%')->orderBy($title)->limit(25)->pluck($title, 'id')->all() ?? [];
                })
                ->getOptionLabelUsing(function (mixed $value, Get $get): ?string {
                    [$query, $title] = self::records($get('target_type'));

                    return $query?->whereKey($value)->value($title);
                }),
        ];
    }

    private static function records(?string $type): array
    {
        return match ($type) {
            'platform' => [Platform::query(), 'name'],
            'help_article' => [SiteArticle::query()->where('type', 'help'), 'title'],
            default => [null, null],
        };
    }
}

<?php

namespace App\Filament\Resources;

use App\Content\Publishing;
use App\Filament\Resources\SiteBannerResource\Pages\ListSiteBanners;
use App\Models\SiteBanner;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SiteBannerResource extends Resource
{
    protected static ?string $model = SiteBanner::class;

    protected static ?string $pluralModelLabel = 'Banner';

    protected static string|\UnitEnum|null $navigationGroup = '内容管理';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('content.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('name')->label('名称')->searchable(),
            TextColumn::make('sort_order')->label('顺序')->sortable(),
            TextColumn::make('target_path')->label('跳转链接')->state(fn (SiteBanner $record) => app(Publishing::class)->bannerTarget($record)),
            IconColumn::make('active')->label('启用')->boolean(),
        ])->recordActions([
            Action::make('edit_banner')->label('编辑')->authorize(fn () => self::canViewAny())->schema(self::fields())
                ->fillForm(fn (SiteBanner $record): array => $record->only(['name', 'image_url', 'sort_order', 'active'])
                    + ['target_path' => app(Publishing::class)->bannerTarget($record) ?? '/'])
                ->action(fn (Action $action, SiteBanner $record, array $data) => ActionForm::submit($action, fn () => self::save($record, $data))),
        ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_banner')->label('新增 Banner')->authorize(fn () => self::canViewAny())
            ->schema(self::fields(creating: true))
            ->action(fn (Action $action, array $data) => ActionForm::submit($action, fn () => self::save(null, $data)));
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteBanners::route('/')];
    }

    private static function fields(bool $creating = false): array
    {
        return [
            TextInput::make('name')->label('名称')->maxLength(150)->required(),
            PublicImageField::make('上传 Banner 图片')->required($creating),
            PublicImageField::current('image_url'),
            TextInput::make('sort_order')->label('展示顺序')->integer()->minValue(0)->maxValue(100000)->default(0)->required(),
            Toggle::make('active')->label('启用')->default(false),
            TextInput::make('target_path')->label('跳转链接')->placeholder('#/announcements/12')->maxLength(1024)->required(),
        ];
    }

    private static function save(?SiteBanner $record, array $data): void
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);
        app(Publishing::class)->banner($record, PublicImageField::retain($data, 'image_url', $record?->image_url), $operator);
    }
}

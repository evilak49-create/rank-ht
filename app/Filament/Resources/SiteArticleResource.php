<?php

namespace App\Filament\Resources;

use App\Content\Publishing;
use App\Models\SiteArticle;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class SiteArticleResource extends Resource
{
    protected static ?string $model = SiteArticle::class;

    protected static string|\UnitEnum|null $navigationGroup = '内容管理';

    protected static string $articleType;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('content.manage');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', static::$articleType);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('标题')->searchable(),
            IconColumn::make('published')->label('发布')->boolean(),
            TextColumn::make('published_at')->label('发布时间（UTC）')->dateTime()->sortable(),
            TextColumn::make('sort_order')->label('顺序')->visible(static::$articleType === 'help'),
        ])->filters([TernaryFilter::make('published')->label('发布状态')])
            ->recordActions([
                Action::make('edit_article')->label('编辑')->authorize(fn () => static::canViewAny())
                    ->schema(static::fields())
                    ->fillForm(fn (SiteArticle $record): array => $record->only([
                        'title', 'body', 'published', 'published_at', 'steps', 'sort_order', 'target_type', 'target_id',
                    ]))
                    ->action(fn (Action $action, SiteArticle $record, array $data) => ActionForm::submit($action, fn () => static::save($record, $data))),
            ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_article')->label('新增')->authorize(fn () => static::canViewAny())
            ->schema(static::fields())
            ->action(fn (Action $action, array $data) => ActionForm::submit($action, fn () => static::save(null, $data)));
    }

    private static function fields(): array
    {
        $fields = [
            TextInput::make('title')->label('标题')->maxLength(150)->required(),
            Textarea::make('body')->label('正文')->rows(5)->maxLength(12000)->required(),
            Toggle::make('published')->label('发布')->default(false),
        ];

        if (static::$articleType === 'announcement') {
            return [...$fields, DateTimePicker::make('published_at')->label('发布时间（UTC）')
                ->seconds(false)->helperText('发布时留空会立即生效。')];
        }

        return [
            ...$fields, TextInput::make('sort_order')->label('展示顺序')->integer()->minValue(0)->maxValue(100000)->default(0),
            Repeater::make('steps')->label('教程步骤')->defaultItems(0)->maxItems(30)->schema([
                TextInput::make('title')->label('步骤标题')->maxLength(150)->required(),
                Textarea::make('body')->label('步骤说明')->maxLength(1500)->required(),
                PublicImageField::make('上传步骤图片'),
                PublicImageField::current('image_url', retainSubmitted: true),
                PublicImageField::remove('image_url'),
            ]),
            ...SiteTargetFields::make(false),
        ];
    }

    private static function save(?SiteArticle $record, array $data): void
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);
        if (static::$articleType === 'help') {
            $existingUrls = array_column($record?->steps ?? [], 'image_url');
            $data['steps'] = array_map(function (array $step) use ($existingUrls): array {
                $url = $step['image_url'] ?? null;

                return PublicImageField::retain($step, 'image_url', in_array($url, $existingUrls, true) ? $url : null);
            }, $data['steps'] ?? []);
        }
        app(Publishing::class)->article(static::$articleType, $record, $data, $operator);
    }
}

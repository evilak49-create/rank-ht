<?php

namespace App\Filament\Resources;

use App\Content\Publishing;
use App\Models\SiteArticle;
use App\Models\User;
use App\Storage\PublicImages;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
            IconColumn::make('is_live')->label('已发布')->boolean()->state(static::isLive(...)),
            TextColumn::make('published_at')->label('发布时间（UTC）')->dateTime()->sortable(),
            TextColumn::make('sort_order')->label('顺序')->visible(static::$articleType === 'help'),
        ])->filters([TernaryFilter::make('published')->label('发布状态')->queries(
            true: fn (Builder $query): Builder => $query->public(),
            false: fn (Builder $query): Builder => $query->where(fn (Builder $draft): Builder => $draft
                ->where('published', false)->orWhereNull('published_at')->orWhere('published_at', '>', now())),
        )])
            ->recordActions([
                Action::make('edit_article')->label('编辑')->authorize(fn () => static::canViewAny())
                    ->schema(static::fields())
                    ->fillForm(fn (SiteArticle $record): array => $record->only(['title', 'published'])
                        + ['body_html' => app(Publishing::class)->editorBody($record)])
                    ->action(fn (Action $action, SiteArticle $record, array $data) => ActionForm::submit($action, fn () => static::save($record, $data))),
                Action::make('publish')->label('确认发布')->requiresConfirmation()->authorize(fn () => static::canViewAny())
                    ->visible(fn (SiteArticle $record): bool => static::$articleType === 'announcement' && ! static::isLive($record))
                    ->action(fn (SiteArticle $record) => app(Publishing::class)->publish($record, Filament::auth()->user())),
                Action::make('sort_help')->label('调整顺序')->authorize(fn () => static::canViewAny())
                    ->visible(fn (): bool => static::$articleType === 'help')
                    ->schema([TextInput::make('sort_order')->label('展示顺序')->integer()->minValue(0)->maxValue(100000)->required()])
                    ->fillForm(fn (SiteArticle $record): array => ['sort_order' => $record->sort_order])
                    ->action(fn (SiteArticle $record, array $data) => $record->update([
                        'sort_order' => $data['sort_order'], 'user_id' => Filament::auth()->id(),
                    ])),
            ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_article')->label('新增')->authorize(fn () => static::canViewAny())
            ->modalSubmitActionLabel(static::$articleType === 'announcement' ? '保存草稿' : '保存')
            ->schema(static::fields())
            ->action(fn (Action $action, array $data) => ActionForm::submit($action, fn () => static::save(null, $data)));
    }

    private static function fields(): array
    {
        return [
            TextInput::make('title')->label(static::$articleType === 'help' ? '问题' : '标题')->maxLength(150)->required(),
            RichEditor::make('body_html')->label(static::$articleType === 'help' ? '回答' : '正文')->required()
                ->toolbarButtons([
                    ['h2', 'h3', 'bold', 'italic', 'underline'],
                    ['bulletList', 'orderedList', 'blockquote', 'link', 'attachFiles'],
                    ['undo', 'redo'],
                ])
                ->fileAttachmentsAcceptedFileTypes(array_keys(PublicImages::MIMES))
                ->fileAttachmentsMaxSize(PublicImages::MAX_KIB)
                ->saveUploadedFileAttachmentUsing(function (TemporaryUploadedFile $file): string {
                    abort_unless(static::canViewAny(), 403);

                    return app(PublicImages::class)->apply(['image_upload' => $file], 'image_url', fn (array $data): string => $data['image_url']);
                })
                ->getFileAttachmentUrlUsing(fn (string $file): string => $file),
            ...(static::$articleType === 'help' ? [Toggle::make('published')->label('发布')->default(true)] : []),
        ];
    }

    private static function save(?SiteArticle $record, array $data): void
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);
        $data += [
            'published' => $record?->published ?? false,
            'published_at' => $record?->published_at,
            'sort_order' => $record?->sort_order ?? 0,
            'target_type' => $record?->target_type,
            'target_id' => $record?->target_id,
        ];
        app(Publishing::class)->article(static::$articleType, $record, $data, $operator);
    }

    private static function isLive(SiteArticle $record): bool
    {
        return $record->published && $record->published_at?->lte(now()) === true;
    }
}

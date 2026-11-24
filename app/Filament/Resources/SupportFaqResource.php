<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportFaqResource\Pages\ListSupportFaqs;
use App\Models\SiteArticle;
use App\Models\SupportFaq;
use App\Models\User;
use App\SupportDesk\CustomerSupport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SupportFaqResource extends Resource
{
    protected static ?string $model = SupportFaq::class;

    protected static ?string $pluralModelLabel = '常见问题';

    protected static string|\UnitEnum|null $navigationGroup = '客服管理';

    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('support.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('question')->label('问题')->searchable(),
            IconColumn::make('published')->label('已发布')->boolean(),
            TextColumn::make('updated_at')->label('更新时间')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('published')->label('发布状态')])
            ->recordActions([
                Action::make('edit_faq')->label('编辑问答')->authorize(fn () => self::canViewAny())
                    ->fillForm(fn (SupportFaq $record): array => $record->only(['question', 'aliases', 'answer', 'published', 'help_article_id']))
                    ->schema(self::fields())->action(fn (SupportFaq $record, array $data) => app(CustomerSupport::class)->saveFaq($record, $data, Filament::auth()->user())),
            ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_faq')->label('新增问答')->authorize(fn () => self::canViewAny())
            ->schema(self::fields())->action(fn (array $data) => app(CustomerSupport::class)->saveFaq(null, $data, Filament::auth()->user()));
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportFaqs::route('/')];
    }

    private static function fields(): array
    {
        return [
            TextInput::make('question')->label('问题')->required()->maxLength(200),
            TagsInput::make('aliases')->label('相似问法')->helperText('输入一种问法后按 Enter 添加。'),
            Textarea::make('answer')->label('标准答案')->required()->maxLength(4000)->rows(5),
            Select::make('help_article_id')->label('关联帮助文章')->searchable()
                ->options(fn (): array => SiteArticle::query()->where('type', 'help')->orderBy('title')->pluck('title', 'id')->all()),
            Toggle::make('published')->label('发布')->default(false),
        ];
    }
}

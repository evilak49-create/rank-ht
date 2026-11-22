<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformReviewResource\Pages\ListPlatformReviews;
use App\Models\PlatformReview;
use App\Models\User;
use App\Platforms\PlatformParticipation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PlatformReviewResource extends Resource
{
    protected static ?string $model = PlatformReview::class;

    protected static ?string $pluralModelLabel = '平台评价';

    protected static string|\UnitEnum|null $navigationGroup = '平台与保障';

    protected static ?int $navigationSort = 20;

    private const STATUSES = ['pending' => '待审核', 'approved' => '已发布', 'rejected' => '已驳回'];

    private const SOURCES = ['member' => '会员投稿', 'ai' => 'AI 评价'];

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('platforms.view-reviews');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('member.phone')->label('提交会员')->searchable(),
            TextColumn::make('source')->label('来源')->badge()->formatStateUsing(fn (string $state): string => self::SOURCES[$state]),
            TextColumn::make('rating')->label('星级')->placeholder('—')->formatStateUsing(fn (int $state): string => $state.' 星'),
            TextColumn::make('body')->label('评价内容')->limit(80)->wrap(),
            TextColumn::make('status')->label('审核状态')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state]),
            TextColumn::make('review_reason')->label('驳回原因')->limit(80)->toggleable(),
            TextColumn::make('reviewer.username')->label('审核人')->toggleable(),
            TextColumn::make('reviewed_at')->label('审核时间')->dateTime()->sortable(),
            TextColumn::make('created_at')->label('提交时间')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('审核状态')->options(self::STATUSES),
            SelectFilter::make('platform')->label('平台')->relationship('platform', 'name')->searchable(),
            SelectFilter::make('source')->label('来源')->options(self::SOURCES),
            SelectFilter::make('batch')->label('生成批次')->relationship('batch', 'id')->searchable(),
        ])->recordActions([
            Action::make('read')->label('查看全文')->authorize(fn (): bool => self::canViewAny())
                ->modalDescription(fn (PlatformReview $record): string => $record->body)
                ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
            Action::make('approve')->label('批准并发布')->requiresConfirmation()
                ->authorize(fn (): bool => self::canModerate())->visible(fn (PlatformReview $record): bool => $record->status === 'pending')
                ->modalDescription(fn (PlatformReview $record): string => $record->body)
                ->action(fn (PlatformReview $record) => app(PlatformParticipation::class)->moderate($record, true, Filament::auth()->user())),
            Action::make('reject')->label('驳回')->color('danger')
                ->authorize(fn (): bool => self::canModerate())->visible(fn (PlatformReview $record): bool => $record->status === 'pending')
                ->schema([Textarea::make('reason')->label('驳回原因')->maxLength(300)->required()])
                ->action(fn (PlatformReview $record, array $data) => app(PlatformParticipation::class)->moderate($record, false, Filament::auth()->user(), $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformReviews::route('/')];
    }

    private static function canModerate(): bool
    {
        return self::canViewAny() && Filament::auth()->user()->hasPermission('platforms.moderate-reviews');
    }
}

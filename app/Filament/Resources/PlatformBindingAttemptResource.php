<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlatformBindingAttemptResource\Pages\ListPlatformBindingAttempts;
use App\Models\PlatformBindingAttempt;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlatformBindingAttemptResource extends Resource
{
    protected static ?string $model = PlatformBindingAttempt::class;

    protected static ?string $pluralModelLabel = '绑定记录';

    protected static string|\UnitEnum|null $navigationGroup = '会员管理';

    protected static ?int $navigationSort = 30;

    private const STATUSES = ['processing' => '处理中', 'succeeded' => '成功', 'failed' => '失败', 'timed_out' => '已超时'];

    private const METHODS = ['automatic' => '平台自动回调', 'self' => '会员自助绑定'];

    public static function canViewAny(): bool
    {
        return MemberResource::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('member.phone')->label('会员手机号')->searchable()
                ->url(fn (PlatformBindingAttempt $record): string => MemberResource::getUrl('view', ['record' => $record->member_id])),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('platform_account_id')->label('平台账号')->searchable()->placeholder('—'),
            TextColumn::make('method')->label('方式')->formatStateUsing(fn (string $state): string => self::METHODS[$state]),
            TextColumn::make('status')->label('状态')->state(fn (PlatformBindingAttempt $record): string => $record->displayStatus())
                ->formatStateUsing(fn (string $state): string => self::STATUSES[$state])->badge()
                ->color(fn (string $state): string => match ($state) {
                    'succeeded' => 'success', 'failed' => 'danger', 'timed_out' => 'warning', default => 'gray',
                }),
            TextColumn::make('created_at')->label('发起时间')->dateTime('Y-m-d H:i:s')->sortable(),
            TextColumn::make('callback_at')->label('回调时间')->dateTime('Y-m-d H:i:s')->placeholder('—'),
            TextColumn::make('updated_at')->label('更新时间')->dateTime('Y-m-d H:i:s')->sortable(),
            TextColumn::make('failure_reason')->label('失败原因')->wrap()->placeholder('—'),
        ])->filters([
            SelectFilter::make('member')->label('会员手机号')->relationship('member', 'phone')->searchable(),
            SelectFilter::make('platform')->label('平台')->relationship('platform', 'name')->searchable(),
            SelectFilter::make('method')->label('方式')->options(self::METHODS),
            SelectFilter::make('status')->label('状态')->options(self::STATUSES)
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['value'] ?? null, fn (Builder $query, string $status): Builder => $query->withDisplayStatus($status))),
            Filter::make('started')->label('发起时间')->schema([
                DatePicker::make('from')->label('开始日期'), DatePicker::make('until')->label('结束日期'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformBindingAttempts::route('/')];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportConversationResource\Pages\ListSupportConversations;
use App\Filament\Resources\SupportConversationResource\Pages\ViewSupportConversation;
use App\Models\SupportConversation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupportConversationResource extends Resource
{
    protected static ?string $model = SupportConversation::class;

    protected static ?string $pluralModelLabel = '对话列表';

    protected static ?string $modelLabel = '客服会话';

    protected static string|UnitEnum|null $navigationGroup = '客服管理';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('support.manage');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('last_message_at')->with(['member:id,phone', 'operator:id,username,name']);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('last_message_at', 'desc')
            ->emptyStateHeading('暂无客服对话')
            ->poll('10s')
            ->columns([
                TextColumn::make('public_id')->label('会话编号')->searchable()->copyable(),
                TextColumn::make('member.phone')->label('会员手机号')->placeholder('游客')->searchable(),
                TextColumn::make('last_message')->label('最后一条消息')->limit(50),
                TextColumn::make('last_message_at')->label('最后活跃时间')->dateTime()->sortable(),
                TextColumn::make('mode')->label('接待状态')->badge()
                    ->state(fn (SupportConversation $record): string => $record->operator_id === null ? '未接管' : '人工接待'),
                TextColumn::make('operator.name')->label('当前接待人')->placeholder('—')
                    ->formatStateUsing(fn (?string $state, SupportConversation $record): ?string => $record->operator?->username ?: $state),
            ])
            ->filters([
                SelectFilter::make('visitor_type')->label('访客类型')->options(['member' => '会员', 'guest' => '游客'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'member' => $query->whereNotNull('member_id'),
                        'guest' => $query->whereNull('member_id'),
                        default => $query,
                    }),
                SelectFilter::make('mode')->label('接待状态')->options(['ai' => '未接管', 'human' => '人工接待'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'ai' => $query->whereNull('operator_id'),
                        'human' => $query->whereNotNull('operator_id'),
                        default => $query,
                    }),
            ])
            ->recordActions([ViewAction::make()->label('查看对话')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportConversations::route('/'),
            'view' => ViewSupportConversation::route('/{record}'),
        ];
    }
}

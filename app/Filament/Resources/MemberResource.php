<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MemberResource\Pages\ListMembers;
use App\Models\Member;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $modelLabel = '会员';

    protected static ?string $pluralModelLabel = '会员列表';

    protected static string|\UnitEnum|null $navigationGroup = '会员管理';

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('members.view');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('id', ctype_digit($search) ? $search : 0)),
                TextColumn::make('phone')->label('手机号')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('phone', $search)),
                TextColumn::make('created_at')->label('注册时间')->dateTime()->sortable(),
                TextColumn::make('last_login_at')->label('最后登录时间')->dateTime()->sortable(),
                TextColumn::make('disabled_at')->label('状态')
                    ->state(fn (Member $record): string => $record->disabled_at === null ? '正常' : '禁用')
                    ->badge()->color(fn (string $state): string => $state === '正常' ? 'success' : 'danger'),
            ])
            ->filters([
                TernaryFilter::make('disabled_at')->label('账号状态')->nullable()
                    ->trueLabel('禁用')->falseLabel('正常'),
            ])
            ->recordActions([
                self::statusAction(true),
                self::statusAction(false),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMembers::route('/')];
    }

    private static function statusAction(bool $disabled): Action
    {
        return Action::make($disabled ? 'disable' : 'restore')
            ->label($disabled ? '禁用' : '恢复')
            ->color($disabled ? 'danger' : 'success')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => ($record->disabled_at === null) === $disabled)
            ->authorize(function (): bool {
                $operator = Filament::auth()->user();

                return $operator instanceof User && $operator->hasPermission('members.change-status');
            })
            ->action(function (Member $record) use ($disabled): void {
                $operator = Filament::auth()->user();
                abort_unless(
                    $operator instanceof User && $operator->hasPermission('members.change-status'),
                    403,
                );
                $record->changeDisabledStatus($disabled, $operator);
            });
    }
}

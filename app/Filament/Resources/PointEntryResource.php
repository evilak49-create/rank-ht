<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PointEntryResource\Pages\ListPointEntries;
use App\Models\PointEntry;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PointEntryResource extends Resource
{
    protected static ?string $model = PointEntry::class;

    protected static ?string $modelLabel = '积分流水';

    protected static ?string $pluralModelLabel = '积分流水';

    protected static string|\UnitEnum|null $navigationGroup = '积分管理';

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('points.view');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('流水 ID')->sortable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('direction')->label('方向')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'credit' ? '加分' : '扣分'),
            TextColumn::make('amount')->label('变更积分')->numeric(),
            TextColumn::make('balance_before')->label('变更前')->numeric(),
            TextColumn::make('balance_after')->label('变更后')->numeric(),
            TextColumn::make('reason')->label('原因')->searchable()->wrap(),
            TextColumn::make('operator.username')->label('操作账号'),
            TextColumn::make('created_at')->label('操作时间')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('direction')->label('方向')->options(['credit' => '加分', 'debit' => '扣分']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPointEntries::route('/')];
    }
}

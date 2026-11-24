<?php

namespace App\Filament\Resources\MemberResource\RelationManagers;

use App\Filament\Resources\MemberResource;
use App\Models\PlatformBinding;
use App\Points\RescueFund;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlatformBindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'platformBindings';

    protected static ?string $title = '平台绑定';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemberResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('platform'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('platform.name')->label('平台'),
            TextColumn::make('platform_account_id')->label('平台账号')->searchable(),
            TextColumn::make('created_at')->label('绑定时间')->dateTime('Y-m-d H:i')->sortable(),
            TextColumn::make('protection_status')->label('保障资格')->badge()->state(fn (PlatformBinding $record): string => $record->currentProtectionStatus())
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'active' => '保障中', 'inactive' => '未生效', default => '待确认',
                }),
        ])->recordActions([
            Action::make('recharges')->label('充值记录')
                ->url(fn (PlatformBinding $record): string => MemberResource::getUrl('recharges', [
                    'record' => $record->member_id, 'binding' => $record->id,
                ])),
            Action::make('rescue_eligibility')->label('救援金资格')->modalSubmitAction(false)
                ->authorize(fn (): bool => MemberResource::canViewAny() && (Filament::auth()->user()?->hasPermission('points.view') ?? false))
                ->modalContent(fn (PlatformBinding $record) => view('filament.members.rescue-eligibility', [
                    'assessment' => app(RescueFund::class)->inspectBinding($record),
                ])),
        ]);
    }
}

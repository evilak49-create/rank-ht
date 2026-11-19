<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProtectionClaimResource\Pages\ListProtectionClaims;
use App\Filament\Resources\ProtectionClaimResource\Pages\ViewProtectionClaim;
use App\Models\ClaimEvidence;
use App\Models\ProtectionClaim;
use App\Models\User;
use App\Points\PointRules;
use App\Protection\ProtectionClaims;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProtectionClaimResource extends Resource
{
    protected static ?string $model = ProtectionClaim::class;

    protected static ?string $pluralModelLabel = '申诉案件';

    protected static string|\UnitEnum|null $navigationGroup = '保障与申诉';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('protection.claims.view');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('案件编号')->searchable(),
            TextColumn::make('member.phone')->label('提交会员')->searchable(),
            TextColumn::make('platform_name')->label('平台')->searchable(),
            TextColumn::make('platform_account_id')->label('平台账号')->searchable(),
            TextColumn::make('amount_cents')->label('出款金额')->formatStateUsing(self::money(...)),
            TextColumn::make('withdrawn_at')->label('出款时间')->dateTime()->sortable(),
            TextColumn::make('created_at')->label('提交时间')->dateTime()->sortable(),
        ])->recordActions([ViewAction::make()->label('查看案件')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('案件编号'),
            TextEntry::make('member.phone')->label('提交会员'),
            TextEntry::make('platform_name')->label('申请时平台'),
            TextEntry::make('platform_account_id')->label('平台账号'),
            TextEntry::make('protection_status')->label('提交时保障状态')
                ->formatStateUsing(fn (string $state): string => ProtectionClaims::STATUSES[$state]),
            TextEntry::make('protection_term_id')->label('提交时条款版本')->placeholder('未发布'),
            TextEntry::make('amount_cents')->label('出款金额')->formatStateUsing(self::money(...)),
            TextEntry::make('withdrawn_at')->label('出款时间')->dateTime(),
            TextEntry::make('created_at')->label('提交时间')->dateTime(),
            TextEntry::make('description')->label('问题说明')->extraAttributes(['class' => 'whitespace-pre-wrap'])->columnSpanFull(),
            RepeatableEntry::make('evidence')->label('申诉凭证')->columnSpanFull()->schema([
                TextEntry::make('position')->hiddenLabel()
                    ->formatStateUsing(fn (int $state): string => '查看图片 '.$state)
                    ->url(fn (ClaimEvidence $record): string => $record->accessUrl('admin'), shouldOpenInNewTab: true),
            ]),
        ])->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProtectionClaims::route('/'),
            'view' => ViewProtectionClaim::route('/{record}'),
        ];
    }

    private static function money(int $state): string
    {
        return 'R$ '.PointRules::display($state, 2);
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProtectionEligibilityResource\Pages\ListProtectionEligibility;
use App\Models\PlatformBinding;
use App\Models\User;
use App\Protection\ProtectionClaims;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProtectionEligibilityResource extends Resource
{
    protected static ?string $model = PlatformBinding::class;

    protected static ?string $slug = 'protection-eligibility';

    protected static ?string $pluralModelLabel = '保障资格';

    protected static string|\UnitEnum|null $navigationGroup = '平台与保障';

    protected static ?int $navigationSort = 50;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('protection.eligibility.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('platform_account_id')->label('平台账号')->searchable(),
            TextColumn::make('protection_status')->label('保障状态')->badge()
                ->formatStateUsing(fn (string $state): string => PlatformBinding::protectionStatusLabel($state)),
        ])->filters([
            SelectFilter::make('protection_status')->label('保障状态')->options(PlatformBinding::PROTECTION_STATUSES),
        ])->recordActions([
            Action::make('set_status')->label('更新资格')
                ->authorize(fn (): bool => self::canViewAny())
                ->fillForm(fn (PlatformBinding $record): array => ['status' => $record->protection_status])
                ->schema([Select::make('status')->label('保障状态')->options(PlatformBinding::PROTECTION_STATUSES)->required()])
                ->action(fn (PlatformBinding $record, array $data) => app(ProtectionClaims::class)->setEligibility(
                    $record, Filament::auth()->user(), $data['status'],
                ))->successNotificationTitle('已更新保障资格'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListProtectionEligibility::route('/')];
    }
}

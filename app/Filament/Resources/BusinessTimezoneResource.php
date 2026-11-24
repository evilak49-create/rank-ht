<?php

namespace App\Filament\Resources;

use App\Activities\BusinessClock;
use App\Filament\Resources\BusinessTimezoneResource\Pages\ListBusinessTimezones;
use App\Models\BusinessTimezone;
use App\Models\User;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BusinessTimezoneResource extends Resource
{
    protected static ?string $model = BusinessTimezone::class;

    protected static ?string $pluralModelLabel = '业务时区';

    protected static string|\UnitEnum|null $navigationGroup = '系统管理';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.manage-business-timezone');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('timezone')->label('时区'),
            TextColumn::make('effective_at')->label('计划生效时间（UTC）')->dateTime()->placeholder('初始化'),
            TextColumn::make('operator.username')->label('操作人'),
            TextColumn::make('created_at')->label('保存时间（UTC）')->dateTime(),
        ]);
    }

    public static function scheduleAction(): Action
    {
        return Action::make('schedule_timezone')->label('设置业务时区')
            ->authorize(fn (): bool => self::canViewAny())
            ->modalDescription(fn (): string => '当前时区为 '.app(BusinessClock::class)->current(now()).'。设定将在原时区下一个自然日零点生效。')
            ->schema([
                Select::make('timezone')->label('新时区')->searchable()
                    ->options(fn (): array => collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $zone) => [$zone => $zone])->all())
                    ->required(),
            ])->action(function (array $data): void {
                $operator = Filament::auth()->user();
                abort_unless($operator instanceof User, 403);
                app(BusinessClock::class)->schedule($data['timezone'], $operator);
            });
    }

    public static function getPages(): array
    {
        return ['index' => ListBusinessTimezones::route('/')];
    }
}

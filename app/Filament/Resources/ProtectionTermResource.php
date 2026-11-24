<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProtectionTermResource\Pages\ListProtectionTerms;
use App\Models\Platform;
use App\Models\ProtectionTerm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ProtectionTermResource extends Resource
{
    protected static ?string $model = ProtectionTerm::class;

    protected static ?string $pluralModelLabel = '保障条款';

    protected static string|\UnitEnum|null $navigationGroup = '平台管理';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        $staff = Filament::auth()->user();

        return $staff instanceof User && $staff->hasPermission('protection.terms.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('版本')->sortable(),
            TextColumn::make('platform.name')->label('平台')->searchable(),
            TextColumn::make('title')->label('标题')->searchable(),
            TextColumn::make('created_at')->label('发布时间')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('read')->label('查看条款')->authorize(fn () => self::canViewAny())
                ->modalDescription(fn (ProtectionTerm $record): string => $record->body)
                ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
        ]);
    }

    public static function publishAction(): Action
    {
        return Action::make('publish')->label('发布条款版本')->authorize(fn () => self::canViewAny())
            ->schema([
                Select::make('platform_id')->label('平台')->required()->searchable()
                    ->options(fn (): array => Platform::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->exists('platforms', 'id'),
                TextInput::make('title')->label('标题')->required()->maxLength(160),
                Textarea::make('body')->label('条款正文')->required()->maxLength(12000)->rows(12),
            ])->action(function (array $data): void {
                abort_unless(self::canViewAny(), 403);
                DB::transaction(function () use ($data): void {
                    Platform::query()->lockForUpdate()->findOrFail($data['platform_id']);
                    ProtectionTerm::create($data + ['user_id' => Filament::auth()->id()]);
                }, 3);
            })->successNotificationTitle('已发布保障条款');
    }

    public static function getPages(): array
    {
        return ['index' => ListProtectionTerms::route('/')];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProtectionTermResource\Pages\ListProtectionTerms;
use App\Models\ProtectionTerm;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
        return $table->modifyQueryUsing(fn ($query) => $query->with('platform'))->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('版本')->sortable(),
            TextColumn::make('scope')->label('适用范围')
                ->state(fn (ProtectionTerm $record): string => $record->platform?->name ?? '全站'),
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
            ->modalDescription('此版本适用于所有平台，标题及正文中的变量会按访问者及当前平台替换。')
            ->schema([
                TextInput::make('title')->label('标题')->required()->maxLength(160),
                Textarea::make('body')->label('条款正文')->required()->maxLength(12000)->rows(12)
                    ->helperText('可用变量：'.implode('、', ProtectionTerm::PLACEHOLDERS)),
            ])->action(function (array $data): void {
                abort_unless(self::canViewAny(), 403);
                ProtectionTerm::create([
                    'title' => $data['title'], 'body' => $data['body'], 'user_id' => Filament::auth()->id(),
                ]);
            })->successNotificationTitle('已发布全站保障条款');
    }

    public static function getPages(): array
    {
        return ['index' => ListProtectionTerms::route('/')];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedPacketRoundResource\Pages\ListRedPacketRounds;
use App\Models\RedPacketRound;
use App\Models\RedPacketRule;
use App\Models\User;
use App\Points\PointRules;
use App\RedPackets\RedPackets;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RedPacketRoundResource extends Resource
{
    protected static ?string $model = RedPacketRound::class;

    protected static ?string $pluralModelLabel = '红包轮次';

    protected static string|\UnitEnum|null $navigationGroup = '会员与活动';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return self::allowed('red-packets.view') || self::allowed('red-packets.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('轮次')->sortable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('red_packet_rule_id')->label('规则版本'),
            TextColumn::make('threshold_cents')->label('提现门槛')->formatStateUsing(self::money(...)),
            TextColumn::make('initial_cents')->label('初始红包')->formatStateUsing(self::money(...)),
            TextColumn::make('balance_cents')->label('红包余额')->formatStateUsing(self::money(...)),
            TextColumn::make('frozen_cents')->label('冻结金额')->formatStateUsing(self::money(...)),
            TextColumn::make('created_at')->label('开启时间')->dateTime(),
            TextColumn::make('closed_at')->label('到账结清时间')->dateTime(),
        ]);
    }

    public static function thresholdAction(): Action
    {
        return Action::make('configure_threshold')->label('设置提现门槛')
            ->authorize(fn () => self::allowed('red-packets.manage'))
            ->fillForm(fn () => ['threshold' => PointRules::display(RedPacketRule::query()->latest('id')->firstOrFail()->threshold_cents, 2)])
            ->schema([TextInput::make('threshold')->label('新轮次提现门槛（BRL）')->required()
                ->maxLength(9)->helperText('新门槛在下一轮开启时生效。')])
            ->action(fn (array $data) => app(RedPackets::class)->saveThreshold($data['threshold'], Filament::auth()->user()))
            ->successNotificationTitle('已保存提现门槛');
    }

    public static function getPages(): array
    {
        return ['index' => ListRedPacketRounds::route('/')];
    }

    public static function pageCopyAction(): Action
    {
        return Action::make('configure_page_copy')->label('设置红包活动文案')
            ->authorize(fn () => self::allowed('red-packets.manage'))
            ->fillForm(fn (): array => app(RedPackets::class)->pageCopy())
            ->schema([
                TextInput::make('opening_title')->label('活动标题')->required()->maxLength(80),
                Textarea::make('opening_description')->label('活动简介')->maxLength(240)->rows(2),
                TextInput::make('open_label')->label('拆红包按钮文案')->required()->maxLength(30),
                TextInput::make('balance_title')->label('累计红包标题')->required()->maxLength(80),
                TextInput::make('invite_label')->label('邀请按钮文案')->required()->maxLength(30),
                TextInput::make('rules_title')->label('活动规则标题')->required()->maxLength(80),
                RichEditor::make('rules_html')->label('活动规则')->required()->fileAttachments(false)
                    ->toolbarButtons([
                        ['h2', 'h3', 'bold', 'italic', 'underline'],
                        ['bulletList', 'orderedList', 'blockquote', 'link'],
                        ['undo', 'redo'],
                    ])->helperText('保存后会更新会员红包页面的文案。'),
            ])->action(fn (array $data) => app(RedPackets::class)->savePageCopy($data, Filament::auth()->user()))
            ->successNotificationTitle('红包活动文案已更新');
    }

    private static function money(int $state): string
    {
        return 'R$ '.PointRules::display($state, 2);
    }

    private static function allowed(string $permission): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission($permission);
    }
}

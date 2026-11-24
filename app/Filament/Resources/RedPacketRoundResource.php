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
use Illuminate\Support\Facades\DB;

class RedPacketRoundResource extends Resource
{
    protected static ?string $model = RedPacketRound::class;

    protected static ?string $pluralModelLabel = '红包管理';

    protected static string|\UnitEnum|null $navigationGroup = '活动管理';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return self::allowed('red-packets.view') || self::allowed('red-packets.manage');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('红包编号')->sortable()->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('invites_count')->label('已结算邀请')->counts('invites'),
            TextColumn::make('red_packet_rule_id')->label('规则版本'),
            TextColumn::make('threshold_cents')->label('提现门槛')->formatStateUsing(self::money(...)),
            TextColumn::make('initial_cents')->label('初始红包')->formatStateUsing(self::money(...)),
            TextColumn::make('balance_cents')->label('红包余额')->formatStateUsing(self::money(...)),
            TextColumn::make('frozen_cents')->label('冻结金额')->formatStateUsing(self::money(...)),
            TextColumn::make('created_at')->label('开启时间')->dateTime(),
            TextColumn::make('closed_at')->label('到账结清时间')->dateTime(),
        ])->recordActions([
            Action::make('cashflow')->label('红包流水')->modalSubmitAction(false)
                ->authorize(fn (): bool => self::canViewAny())
                ->modalContent(fn (RedPacketRound $record) => view('filament.red-packet-ledger', [
                    'entries' => DB::table('red_packet_entries')->where('red_packet_round_id', $record->id)->orderByDesc('id')->get(),
                ])),
            Action::make('invitations')->label('邀请明细')->visible(fn (): bool => RedPacketInviteResource::canViewAny())
                ->url(fn (RedPacketRound $record): string => RedPacketInviteResource::getUrl(parameters: [
                    'filters' => ['packet' => ['id' => (string) $record->id]],
                ])),
        ]);
    }

    public static function ratioAction(): Action
    {
        $labels = [
            'initial_min' => '初始比例最小值', 'initial_max' => '初始比例最大值',
            'invite_min' => '邀请比例最小值', 'invite_max' => '邀请比例最大值',
        ];

        return Action::make('configure_ratios')->label('设置红包比例')
            ->authorize(fn (): bool => self::allowed('red-packets.manage'))
            ->fillForm(fn (): array => array_map(fn (int $bps): string => PointRules::display($bps, 2),
                RedPacketRule::query()->latest('id')->firstOrFail()->ratios()))
            ->schema(array_map(fn (string $key, string $label) => TextInput::make($key)->label($label)
                ->inputMode('decimal')->suffix('%')->required(), array_keys($labels), $labels))
            ->action(fn (Action $action, array $data) => ActionForm::submit($action,
                fn () => app(RedPackets::class)->saveRatios($data, Filament::auth()->user())))
            ->successNotificationTitle('已保存红包比例');
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

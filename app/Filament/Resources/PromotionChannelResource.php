<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PromotionChannelResource\Pages\CreatePromotionChannel;
use App\Filament\Resources\PromotionChannelResource\Pages\ListPromotionChannels;
use App\Models\PromotionChannel;
use App\Models\User;
use App\Promotion\PromotionReport;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PromotionChannelResource extends Resource
{
    protected static ?string $model = PromotionChannel::class;

    protected static ?string $modelLabel = '渠道';

    protected static ?string $navigationLabel = '渠道管理';

    protected static string|\UnitEnum|null $navigationGroup = '会员管理';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('members.view');
    }

    public static function canCreate(): bool
    {
        $operator = Filament::auth()->user();

        return self::canViewAny() && $operator instanceof User && $operator->hasPermission('members.manage-promotion-channels');
    }

    public static function canEdit(Model $record): bool
    {
        return self::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('渠道名称')->required()->maxLength(100),
            Textarea::make('notes')->label('渠道备注')->maxLength(2000),
        ]);
    }

    public static function table(Table $table): Table
    {
        $report = app(PromotionReport::class);

        return $table->defaultSort('id', 'desc')
            ->modifyQueryUsing(function (Builder $query, HasTable $livewire) use ($report): Builder {
                $period = $livewire->getTableFilterState('period');
                $today = $report->today();
                $from = $period['from'] ?? $today;
                $until = $period['until'] ?? $today;

                return $report->query($query, min($from, $until), max($from, $until));
            })
            ->columns([
                TextColumn::make('name')->label('渠道名称')->searchable(),
                TextColumn::make('promotion_url')->label('推广网址')
                    ->state(fn (PromotionChannel $record): string => $record->promotionUrl())->copyable(),
                TextColumn::make('notes')->label('渠道备注')->wrap()->placeholder('—'),
                self::metric('visit_count', '访问 / 访问成本'),
                self::metric('registration_count', '注册 / 注册成本'),
                self::metric('deposit_count', '存款 / 存款成本'),
                TextColumn::make('expense_cents')->label('推广费用')->placeholder('—')
                    ->formatStateUsing(fn (int $state): string => self::money($state)),
            ])
            ->filters([
                Filter::make('period')->label('统计日期')->schema([
                    DatePicker::make('from')->label('开始日期')->default(fn (): string => $report->today()),
                    DatePicker::make('until')->label('结束日期')->default(fn (): string => $report->today()),
                ]),
                Filter::make('channel')->label('渠道编号')
                    ->schema([TextInput::make('id')->label('渠道编号')->integer()])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['id'] ?? null), fn (Builder $query): Builder => $query->whereKey((int) $data['id']),
                    )),
            ])->recordActions([
                Action::make('expense')->label('录入或修改费用')->authorize(fn (): bool => self::canCreate())
                    ->modalHeading('每日推广费用')
                    ->fillForm(fn (PromotionChannel $record): array => [
                        'date' => $report->today(), 'amount' => $report->expense($record, $report->today()),
                    ])
                    ->schema(fn (PromotionChannel $record): array => [
                        DatePicker::make('date')->label('日期')->required()->live()
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set(
                                'amount', $state === null ? null : $report->expense($record, $state),
                            )),
                        TextInput::make('amount')->label('消耗')->prefix('R$')->required()
                            ->rules(['regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/']),
                    ])->action(fn (PromotionChannel $record, array $data) => $report->saveExpense(
                        $record, $data['date'], (string) $data['amount'],
                    ))->successNotificationTitle('推广费用已保存'),
            ]);
    }

    private static function metric(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)->label($label)->formatStateUsing(function (int $state, PromotionChannel $record): string {
            $cost = $state > 0 && $record->expense_cents !== null
                ? self::money((int) round($record->expense_cents / $state))
                : '—';

            return number_format($state).' / '.$cost;
        });
    }

    private static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotionChannels::route('/'),
            'create' => CreatePromotionChannel::route('/create'),
        ];
    }
}

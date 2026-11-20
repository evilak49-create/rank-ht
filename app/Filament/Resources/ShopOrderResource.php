<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopOrderResource\Pages\ListShopOrders;
use App\Models\ShopOrder;
use App\Models\User;
use App\Shop\PhysicalShop;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShopOrderResource extends Resource
{
    protected static ?string $model = ShopOrder::class;

    protected static ?string $pluralModelLabel = '商品订单';

    protected static string|\UnitEnum|null $navigationGroup = '积分与兑换';

    protected static ?int $navigationSort = 50;

    private const STATUSES = ['pending' => '待发货', 'shipped' => '已发货', 'cancelled' => '已取消'];

    public static function canViewAny(): bool
    {
        return self::allowed('shop.view-orders');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('order_number')->label('订单号')->searchable(),
            TextColumn::make('member.phone')->label('会员手机号')->searchable(),
            TextColumn::make('product_name')->label('商品')->searchable(),
            TextColumn::make('quantity')->label('数量')->state(1),
            TextColumn::make('points_price')->label('消耗积分')->numeric(),
            TextColumn::make('status')->label('状态')->badge()->formatStateUsing(fn (string $state) => self::STATUSES[$state]),
            TextColumn::make('created_at')->label('创建时间')->dateTime()->sortable(),
            TextColumn::make('address.recipient')->label('收件人')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('address.phone')->label('收件电话')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('address_text')->label('收件地址')->wrap()->toggleable(isToggledHiddenByDefault: true)
                ->state(fn (ShopOrder $record): string => implode(' · ', array_filter([
                    $record->address['postal_code'], $record->address['state'], $record->address['city'],
                    $record->address['district'] ?? null, $record->address['street'], $record->address['number'],
                    $record->address['complement'] ?? null,
                ]))),
            TextColumn::make('carrier')->label('承运商'),
            TextColumn::make('tracking_number')->label('物流单号'),
            TextColumn::make('cancellation_reason')->label('取消原因')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([SelectFilter::make('status')->label('状态')->options(self::STATUSES)])
            ->recordActions([
                Action::make('view_details')->label('查看详情')->authorize(self::canViewAny(...))
                    ->modalHeading('商品订单详情')->modalSubmitAction(false)->modalCancelActionLabel('关闭')
                    ->schema([
                        TextEntry::make('order_number')->label('订单号')->copyable(),
                        TextEntry::make('product_name')->label('商品'),
                        Section::make('收货信息')->columns(2)->schema([
                            TextEntry::make('address.recipient')->label('收件人')->placeholder('—'),
                            TextEntry::make('address.phone')->label('收件电话')->placeholder('—')->copyable(),
                            TextEntry::make('address.postal_code')->label('邮编（CEP）')->placeholder('—')->copyable(),
                            TextEntry::make('address.state')->label('州')->placeholder('—'),
                            TextEntry::make('address.city')->label('城市')->placeholder('—'),
                            TextEntry::make('address.district')->label('街区')->placeholder('—'),
                            TextEntry::make('address.street')->label('街道')->placeholder('—'),
                            TextEntry::make('address.number')->label('门牌号')->placeholder('—'),
                            TextEntry::make('address.complement')->label('补充地址')->placeholder('—')->columnSpanFull(),
                        ]),
                    ]),
                Action::make('ship')->label('登记发货')->authorize(fn (): bool => self::allowed('shop.ship-orders'))
                    ->visible(fn (ShopOrder $record): bool => $record->status === 'pending')
                    ->schema(self::shippingFields())
                    ->action(fn (ShopOrder $record, array $data) => app(PhysicalShop::class)->ship($record, $data, self::operator())),
                Action::make('edit_tracking')->label('更正物流信息')->authorize(fn (): bool => self::allowed('shop.ship-orders'))
                    ->visible(fn (ShopOrder $record): bool => $record->status === 'shipped')
                    ->fillForm(fn (ShopOrder $record): array => $record->only(['carrier', 'tracking_number']))
                    ->schema(self::shippingFields())
                    ->action(fn (ShopOrder $record, array $data) => app(PhysicalShop::class)->correctTracking($record, $data, self::operator())),
                Action::make('cancel_order')->label('取消订单')->color('danger')
                    ->authorize(fn (): bool => self::allowed('shop.cancel-orders'))
                    ->visible(fn (ShopOrder $record): bool => $record->status === 'pending')
                    ->schema([Textarea::make('reason')->label('取消原因')->maxLength(300)->required()])
                    ->action(fn (ShopOrder $record, array $data) => app(PhysicalShop::class)->cancel($record, $data['reason'], self::operator())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShopOrders::route('/')];
    }

    private static function shippingFields(): array
    {
        return [
            TextInput::make('carrier')->label('承运商')->maxLength(120)->required(),
            TextInput::make('tracking_number')->label('物流单号')->maxLength(120)->required(),
        ];
    }

    private static function allowed(string $permission): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission($permission);
    }

    private static function operator(): User
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);

        return $operator;
    }
}

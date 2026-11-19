<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use App\Shop\PhysicalShop;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $pluralModelLabel = '商品管理';

    protected static string|\UnitEnum|null $navigationGroup = '商城管理';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('shop.manage-products');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('id')->label('商品 ID'),
            TextColumn::make('name')->label('商品名称')->searchable(),
            TextColumn::make('points_price')->label('所需积分')->numeric(),
            TextColumn::make('stock')->label('库存')->numeric(),
            IconColumn::make('is_active')->label('上架')->boolean(),
        ])->recordActions([
            Action::make('edit_product')->label('编辑')->authorize(fn (): bool => self::canViewAny())
                ->schema(self::fields())
                ->fillForm(fn (Product $record) => $record->only(['name', 'description', 'image_url', 'points_price', 'is_active']))
                ->action(fn (Action $action, Product $record, array $data) => ActionForm::submit($action, fn () => app(PhysicalShop::class)->saveProduct(
                    $record, PublicImageField::retain($data, 'image_url', $record->image_url), self::operator(),
                ))),
            Action::make('adjust_stock')->label('调整库存')->authorize(fn (): bool => self::canViewAny())
                ->schema([
                    TextInput::make('delta')->label('库存增减量')->integer()->required()->helperText('正数增加，负数扣减。'),
                    Textarea::make('reason')->label('原因')->maxLength(300)->required(),
                ])->action(fn (Product $record, array $data) => app(PhysicalShop::class)->adjustStock($record, $data, self::operator())),
        ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_product')->label('新增商品')->authorize(fn (): bool => self::canViewAny())
            ->schema(self::fields())
            ->action(fn (Action $action, array $data) => ActionForm::submit($action,
                fn () => app(PhysicalShop::class)->saveProduct(null, PublicImageField::retain($data, 'image_url', null), self::operator())));
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/')];
    }

    private static function fields(): array
    {
        return [
            TextInput::make('name')->label('商品名称')->maxLength(120)->required(),
            Textarea::make('description')->label('说明')->maxLength(2000),
            PublicImageField::make('上传商品图片'),
            PublicImageField::current('image_url'),
            PublicImageField::remove('image_url'),
            TextInput::make('points_price')->label('所需积分')->integer()->minValue(1)->required(),
            Toggle::make('is_active')->label('上架')->default(false),
        ];
    }

    private static function operator(): User
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User, 403);

        return $operator;
    }
}

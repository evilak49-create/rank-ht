<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteDomainResource\Pages\CreateSiteDomain;
use App\Filament\Resources\SiteDomainResource\Pages\ListSiteDomains;
use App\Models\SiteDomain;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class SiteDomainResource extends Resource
{
    protected static ?string $model = SiteDomain::class;

    protected static ?string $slug = 'domains';

    protected static ?string $modelLabel = '域名';

    protected static ?string $pluralModelLabel = '域名列表';

    protected static string|\UnitEnum|null $navigationGroup = '域名管理';

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission(SiteDomain::MANAGE);
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        $normalizeHost = fn (?string $state): string => strtolower(trim($state ?? ''));

        return $schema->components([
            TextInput::make('host')->label('域名')->required()->maxLength(253)
                ->helperText('填写完整域名，例如 example.com。')
                ->mutateStateForValidationUsing($normalizeHost)->dehydrateStateUsing($normalizeHost)
                ->regex('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D')
                ->unique(),
            Select::make('purpose')->label('用途')->options(SiteDomain::PURPOSES)->required()
                ->rules([Rule::in(array_keys(SiteDomain::PURPOSES))]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('host')->label('域名')->searchable(),
            TextColumn::make('purpose')->label('用途')->badge()->formatStateUsing(fn (string $state): string => SiteDomain::PURPOSES[$state]),
            TextColumn::make('created_at')->label('添加时间')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('purpose')->label('用途')->options(SiteDomain::PURPOSES)]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSiteDomains::route('/'), 'create' => CreateSiteDomain::route('/create')];
    }
}

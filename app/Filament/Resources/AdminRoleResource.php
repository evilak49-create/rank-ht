<?php

namespace App\Filament\Resources;

use App\Auth\AdminAccess;
use App\Auth\AdminPermissions;
use App\Filament\Resources\AdminRoleResource\Pages\ListAdminRoles;
use App\Models\AdminRole;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;

class AdminRoleResource extends Resource
{
    protected static ?string $model = AdminRole::class;

    protected static ?string $pluralModelLabel = '角色权限';

    protected static string|\UnitEnum|null $navigationGroup = '系统设置';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.manage-roles');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label('角色')->searchable(),
            TextColumn::make('permissions')->label('权限')->badge()->limitList(4)->expandableLimitedList()
                ->formatStateUsing(fn (string $state): string => AdminPermissions::LABELS[$state] ?? $state),
            TextColumn::make('updated_at')->label('更新时间')->dateTime()->sortable(),
        ])->recordActions([
            Action::make('edit_role')->label('编辑角色')->authorize(fn () => self::canViewAny())
                ->visible(fn (AdminRole $record): bool => array_diff($record->permissions, Filament::auth()->user()->grantedPermissions()) === [])
                ->fillForm(fn (AdminRole $record): array => $record->only(['name', 'permissions']))
                ->schema(self::fields())
                ->action(fn (Action $action, AdminRole $record, array $data) => ActionForm::submit($action,
                    fn () => app(AdminAccess::class)->saveRole($record, $data, Filament::auth()->user()))),
        ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_role')->label('新增角色')->authorize(fn () => self::canViewAny())->schema(self::fields())
            ->action(fn (Action $action, array $data) => ActionForm::submit($action,
                fn () => app(AdminAccess::class)->saveRole(null, $data, Filament::auth()->user())));
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminRoles::route('/')];
    }

    private static function fields(): array
    {
        return [
            TextInput::make('name')->label('角色名称')->required()->maxLength(100),
            Select::make('permissions')->label('权限')->multiple()->searchable()
                ->options(fn (): array => Arr::only(AdminPermissions::LABELS, Filament::auth()->user()->grantedPermissions())),
        ];
    }
}

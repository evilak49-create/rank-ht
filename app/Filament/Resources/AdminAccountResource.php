<?php

namespace App\Filament\Resources;

use App\Auth\AdminAccess;
use App\Auth\AdminCredentials;
use App\Filament\Resources\AdminAccountResource\Pages\ListAdminAccounts;
use App\Models\AdminRole;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AdminAccountResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $pluralModelLabel = '后台账号';

    protected static string|\UnitEnum|null $navigationGroup = '系统设置';

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.manage-admins');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNotNull('username')->with('roles');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('username')->label('账号')->searchable(),
            TextColumn::make('name')->label('显示名称')->searchable(),
            TextColumn::make('roles.name')->label('角色')->badge(),
            IconColumn::make('panel_enabled')->label('启用')->boolean(),
        ])->recordActions([
            Action::make('edit_account')->label('编辑')->authorize(fn () => self::canViewAny())->visible(self::mayManage(...))
                ->fillForm(fn (User $record): array => $record->only(['username', 'name']))->schema(self::fields())
                ->action(fn (Action $action, User $record, array $data) => AdminAccessForm::submit($action,
                    fn () => app(AdminAccess::class)->saveAccount($record, $data, Filament::auth()->user()))),
            Action::make('assign_roles')->label('分配角色')->authorize(fn () => self::canAssign())->visible(self::mayManage(...))
                ->fillForm(fn (User $record): array => ['role_ids' => $record->roles->modelKeys()])
                ->schema([Select::make('role_ids')->label('角色')->multiple()->searchable()
                    ->options(fn (): array => AdminRole::query()->orderBy('name')->get()->filter(fn (AdminRole $role): bool => array_diff($role->permissions, Filament::auth()->user()->grantedPermissions()) === [])->pluck('name', 'id')->all())])
                ->action(fn (Action $action, User $record, array $data) => AdminAccessForm::submit($action,
                    fn () => app(AdminAccess::class)->assignRoles($record, $data['role_ids'] ?? [], Filament::auth()->user()))),
            Action::make('reset_password')->label('重置密码')->authorize(fn () => self::canViewAny())->visible(self::mayManage(...))
                ->schema([self::passwordField()])
                ->action(fn (Action $action, User $record, array $data) => AdminAccessForm::submit($action,
                    fn () => app(AdminAccess::class)->resetPassword($record, $data['password'], Filament::auth()->user()))),
            self::enabledAction(false),
            self::enabledAction(true),
        ]);
    }

    public static function createAction(): Action
    {
        return Action::make('create_account')->label('新增账号')->authorize(fn () => self::canViewAny())
            ->schema([...self::fields(), self::passwordField()])
            ->action(fn (Action $action, array $data) => AdminAccessForm::submit($action,
                fn () => app(AdminAccess::class)->saveAccount(null, $data, Filament::auth()->user())));
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminAccounts::route('/')];
    }

    private static function enabledAction(bool $enabled): Action
    {
        return Action::make($enabled ? 'enable' : 'disable')->label($enabled ? '启用账号' : '停用账号')
            ->authorize(fn () => self::canViewAny())
            ->visible(fn (User $record): bool => $record->panel_enabled !== $enabled && self::mayManage($record))
            ->schema([Checkbox::make('confirm')->label($enabled ? '确认启用此账号' : '确认停用此账号')->accepted()])
            ->action(fn (Action $action, User $record) => AdminAccessForm::submit($action,
                fn () => app(AdminAccess::class)->setEnabled($record, $enabled, Filament::auth()->user())));
    }

    private static function fields(): array
    {
        return [
            TextInput::make('username')->label('登录账号')->required()->rules(AdminCredentials::USERNAME_RULES),
            TextInput::make('name')->label('显示名称（选填）')->maxLength(100),
        ];
    }

    private static function passwordField(): TextInput
    {
        return TextInput::make('password')->label('密码')->password()->required()->autocomplete('new-password')
            ->minLength(5)->maxLength(72);
    }

    private static function canAssign(): bool
    {
        return self::canViewAny() && Filament::auth()->user()->hasPermission('system.manage-roles');
    }

    private static function mayManage(User $record): bool
    {
        return array_diff($record->roles->flatMap(fn (AdminRole $role): array => $role->permissions)->all(),
            Filament::auth()->user()->grantedPermissions()) === [];
    }
}

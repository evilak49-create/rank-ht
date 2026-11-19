<?php

namespace App\Filament\Resources;

use App\Auth\AdminPermissions;
use App\Filament\Resources\AdminAccessAuditResource\Pages\ListAdminAccessAudits;
use App\Models\AdminAccessAudit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AdminAccessAuditResource extends Resource
{
    protected static ?string $model = AdminAccessAudit::class;

    protected static ?string $pluralModelLabel = '后台授权审计';

    protected static string|\UnitEnum|null $navigationGroup = '系统设置';

    private const ACTIONS = [
        'role.created' => '新增角色', 'role.updated' => '修改角色权限',
        'roles.changed' => '调整账号角色', 'account.created' => '新增账号', 'account.updated' => '编辑账号',
        'account.enabled' => '启用账号', 'account.disabled' => '停用账号', 'account.password_reset' => '重置密码',
    ];

    public static function canViewAny(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('system.view-admin-audit');
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('操作时间')->dateTime()->sortable(),
            TextColumn::make('operator.username')->label('操作人')->searchable(),
            TextColumn::make('target.username')->label('目标账号')->searchable(),
            TextColumn::make('role.name')->label('目标角色')->searchable(),
            TextColumn::make('action')->label('操作')->formatStateUsing(fn (string $state): string => self::ACTIONS[$state]),
        ])->filters([
            SelectFilter::make('action')->label('操作类型')->options(self::ACTIONS),
        ])->recordActions([
            Action::make('details')->label('查看变更')->authorize(fn () => self::canViewAny())
                ->visible(fn (AdminAccessAudit $record): bool => $record->details !== [])
                ->modalDescription(self::describe(...))
                ->modalSubmitAction(false)->modalCancelActionLabel('关闭'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAdminAccessAudits::route('/')];
    }

    private static function describe(AdminAccessAudit $record): string
    {
        $details = $record->details;
        if ($record->action === 'roles.changed') {
            return '新增角色 '.(implode('、', $details['added']) ?: '无')
                .'。撤销角色 '.(implode('、', $details['removed']) ?: '无').'。';
        }

        $show = function (?array $state): string {
            if ($state === null) {
                return '无';
            }
            if (isset($state['permissions'])) {
                $labels = array_map(fn (string $permission): string => AdminPermissions::LABELS[$permission] ?? $permission, $state['permissions']);

                return $state['name'].'（'.(implode('、', $labels) ?: '无权限').'）';
            }

            return $state['name'].'（'.$state['username'].'）';
        };

        return '变更前 '.$show($details['before']).'。变更后 '.$show($details['after']).'。';
    }
}

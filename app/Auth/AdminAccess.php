<?php

namespace App\Auth;

use App\Models\AdminRole;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AdminAccess
{
    public function saveRole(?AdminRole $role, array $data, User $operator): void
    {
        $this->change($operator, 'system.manage-roles', function (User $actor) use ($role, $data): void {
            $target = $role === null ? new AdminRole : AdminRole::findOrFail($role->id);
            $fields = Validator::make($data, [
                'name' => ['required', 'string', 'max:100', Rule::unique('admin_roles')->ignore($target->id)],
                'permissions' => ['present', 'array', 'list', 'max:100'],
                'permissions.*' => ['string', 'distinct', Rule::in(array_keys(AdminPermissions::LABELS))],
            ])->validate();
            $this->requireDelegable($actor, [...($target->permissions ?? []), ...$fields['permissions']]);
            sort($fields['permissions']);
            $before = $target->only(['name', 'permissions']);
            $created = ! $target->exists;
            $target->fill($fields);
            if (! $target->isDirty()) {
                return;
            }
            $target->save();
            $this->ensureManagerRemains('permissions');
            $this->audit($actor, $created ? 'role.created' : 'role.updated', [
                'before' => $created ? null : $before, 'after' => $fields,
            ], role: $target);
        });
    }

    public function saveAccount(?User $account, #[SensitiveParameter] array $data, User $operator): void
    {
        $this->change($operator, 'system.manage-admins', function (User $actor) use ($account, $data): void {
            $target = $account === null ? new User : $this->account($actor, $account);
            if (is_string($data['username'] ?? null)) {
                $data['username'] = strtolower($data['username']);
            }
            $fields = Validator::make($data, [
                'name' => ['nullable', 'string', 'max:100'],
                'username' => [...AdminCredentials::USERNAME_RULES, Rule::unique('users')->ignore($target->id)],
                ...($account === null ? ['password' => AdminCredentials::passwordRules()] : []),
            ])->validate();
            $fields['name'] = filled($fields['name'] ?? null) ? $fields['name'] : $fields['username'];
            $before = $target->only(['username', 'name']);
            $target->fill($fields);
            if ($account === null) {
                $target->forceFill(['panel_enabled' => true]);
            }
            if (! $target->isDirty()) {
                return;
            }
            $target->save();
            $this->audit($actor, $account === null ? 'account.created' : 'account.updated', [
                'before' => $account === null ? null : $before, 'after' => $target->only(['username', 'name']),
            ], target: $target);
        });
    }

    public function assignRoles(User $account, array $ids, User $operator): void
    {
        $this->change($operator, 'system.manage-roles', function (User $actor) use ($account, $ids): void {
            abort_unless($actor->hasPermission('system.manage-admins'), 403);
            $target = $this->account($actor, $account);
            $ids = Validator::make(['role_ids' => $ids], [
                'role_ids' => ['present', 'array', 'list', 'max:100'],
                'role_ids.*' => ['required', 'integer', 'distinct', 'exists:admin_roles,id'],
            ])->validate()['role_ids'];
            $roles = AdminRole::query()->whereKey($ids)->get();
            $this->requireDelegable($actor, $roles->flatMap(fn (AdminRole $role): array => $role->permissions)->all());
            $previous = $target->roles()->pluck('name', 'admin_roles.id');
            $changes = $target->roles()->sync($roles->modelKeys());
            if ($changes['attached'] === [] && $changes['detached'] === []) {
                return;
            }
            $this->ensureManagerRemains('role_ids');
            $this->audit($actor, 'roles.changed', [
                'added' => $roles->pluck('name', 'id')->only($changes['attached'])->all(),
                'removed' => $previous->only($changes['detached'])->all(),
            ], target: $target);
        });
    }

    public function setEnabled(User $account, bool $enabled, User $operator): void
    {
        $this->change($operator, 'system.manage-admins', function (User $actor) use ($account, $enabled): void {
            $target = $this->account($actor, $account);
            if ($target->panel_enabled === $enabled) {
                return;
            }

            $target->forceFill(['panel_enabled' => $enabled, 'remember_token' => null])->save();
            $this->ensureManagerRemains('confirm');
            $this->audit($actor, $enabled ? 'account.enabled' : 'account.disabled', [], target: $target);
        });
    }

    public function resetPassword(User $account, #[SensitiveParameter] string $password, User $operator): void
    {
        $this->change($operator, 'system.manage-admins', function (User $actor) use ($account, $password): void {
            $target = $this->account($actor, $account);
            Validator::make(['password' => $password], ['password' => AdminCredentials::passwordRules()])->validate();
            $target->forceFill(['password' => $password, 'remember_token' => null])->save();
            $this->audit($actor, 'account.password_reset', [], target: $target);
        });
    }

    private function change(User $operator, string $permission, Closure $callback): void
    {
        DB::transaction(function () use ($operator, $permission, $callback): void {
            DB::table('admin_access_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($operator->id);
            abort_unless($actor->hasPermission($permission), 403);
            $callback($actor);
        }, 3);
    }

    private function account(User $operator, User $account): User
    {
        $target = User::query()->whereNotNull('username')->findOrFail($account->id);
        $this->requireDelegable($operator, $target->grantedPermissions());

        return $target;
    }

    private function requireDelegable(User $operator, array $permissions): void
    {
        abort_if(array_diff($permissions, $operator->grantedPermissions()) !== [], 403, '只能管理自己拥有的权限。');
    }

    private function ensureManagerRemains(string $field): void
    {
        $managerExists = User::query()->whereNotNull('username')->where('panel_enabled', true)
            ->whereHas('roles', fn ($query) => $query->whereJsonContains('permissions', 'system.manage-admins'))
            ->whereHas('roles', fn ($query) => $query->whereJsonContains('permissions', 'system.manage-roles'))->exists();
        if (! $managerExists) {
            throw ValidationException::withMessages([$field => '须保留至少一个可管理后台账号和授权的启用账号。']);
        }
    }

    private function audit(User $actor, string $action, array $details, ?User $target = null, ?AdminRole $role = null): void
    {
        DB::table('admin_access_audits')->insert([
            'user_id' => $actor->id, 'target_user_id' => $target?->id, 'admin_role_id' => $role?->id,
            'action' => $action, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}

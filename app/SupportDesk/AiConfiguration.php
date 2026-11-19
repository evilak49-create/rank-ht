<?php

namespace App\SupportDesk;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class AiConfiguration
{
    public function __construct(private readonly DeepSeek $provider) {}

    public function active(): ?AiSetting
    {
        return AiSetting::query()->whereNotNull('model')->find(1);
    }

    public function save(#[SensitiveParameter] array $input, User $operator): void
    {
        abort_unless($operator->hasPermission('system.manage-ai'), 403);
        $data = Validator::make($input, [
            'model' => ['required', Rule::in(array_keys(DeepSeek::MODELS))],
            'api_key' => ['required', 'string', 'max:512', 'not_regex:/[[:cntrl:]\s]/'],
        ])->validate();

        try {
            $this->provider->complete($data['model'], $data['api_key'], [['role' => 'user', 'content' => 'Reply with OK.']], 16);
        } catch (Throwable) {
            $this->audit($operator, $data['model'], 'failed');
            throw ValidationException::withMessages(['api_key' => 'DeepSeek 验证失败，请检查 API Key 和模型。']);
        }

        DB::transaction(function () use ($operator, $data): void {
            $setting = AiSetting::query()->lockForUpdate()->findOrFail(1);
            $setting->fill($data + ['user_id' => $operator->id])->save();
            $this->audit($operator, $data['model'], 'activated');
        }, 3);
    }

    private function audit(User $operator, string $model, string $status): void
    {
        DB::table('ai_setting_audits')->insert([
            'user_id' => $operator->id, 'model' => $model, 'status' => $status, 'created_at' => now(),
        ]);
    }
}

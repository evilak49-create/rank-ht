<?php

namespace App\Platforms;

use App\Models\Platform;
use App\Models\PlatformFundTask;
use App\Models\User;
use App\Storage\PublicImages;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class PlatformAdministration
{
    public const PERMISSIONS = [
        'platforms.view', 'platforms.edit', 'platforms.configure-query',
        'platforms.test-query', 'platforms.change-status',
    ];

    public function __construct(private readonly PublicImages $images) {}

    public function create(array $data, User $operator, #[SensitiveParameter] ?array $query = null): Platform
    {
        abort_unless($operator->hasPermission('platforms.edit'), 403);
        $profile = Validator::make($data + ['network' => PlatformNetwork::Panda->value], [
            'network' => ['required', 'string', Rule::enum(PlatformNetwork::class)],
        ])->validate();
        $network = PlatformNetwork::from($profile['network']);
        $draft = null;
        if ($query !== null) {
            abort_unless($operator->hasPermission('platforms.configure-query'), 403);
            $draft = $network->queryDriver()->validateConfiguration($query);
        }

        return $this->saveProfile(new Platform(['network' => $network]), $data, $operator, $draft);
    }

    public function update(Platform $platform, array $data, User $operator): Platform
    {
        abort_unless($operator->hasPermission('platforms.edit'), 403);

        return $this->saveProfile($platform, $data, $operator);
    }

    public function saveQuery(Platform $platform, #[SensitiveParameter] array $data, User $operator): void
    {
        abort_unless($operator->hasPermission('platforms.configure-query'), 403);

        $configuration = $platform->network->queryDriver()->validateConfiguration($data);

        DB::transaction(function () use ($platform, $configuration, $operator): void {
            $current = Platform::query()->lockForUpdate()->findOrFail($platform->id);
            $current->forceFill([
                'draft_query' => $configuration,
                'query_revision' => $current->query_revision + 1,
            ])->save();
            $this->audit($current, $operator, 'query_configured', $current->query_revision);
        }, 3);
    }

    public function queryForm(Platform $platform, User $operator): array
    {
        abort_unless($operator->hasPermission('platforms.configure-query'), 403);
        return $platform->network->queryDriver()->formState($platform->draft_query ?? $platform->active_query);
    }

    public function testQuery(Platform $platform, User $operator): string
    {
        abort_unless($operator->hasPermission('platforms.test-query'), 403);

        $snapshot = Platform::query()->findOrFail($platform->id);
        $isDraft = $snapshot->getRawOriginal('draft_query') !== null;
        $configuration = $isDraft ? $snapshot->draft_query : $snapshot->active_query;
        if ($configuration === null) {
            throw ValidationException::withMessages(['endpoint' => '请先保存平台查询接口。']);
        }

        try {
            $result = $snapshot->network->queryDriver()->lookupTestAccount($configuration) === null ? 'not_found' : 'passed';
        } catch (PlatformQueryFailure $failure) {
            $result = $failure->kind;
        }

        return DB::transaction(function () use ($snapshot, $isDraft, $configuration, $operator, $result): string {
            $current = Platform::query()->lockForUpdate()->findOrFail($snapshot->id);
            $revision = $isDraft ? $snapshot->query_revision : $snapshot->active_query_revision;
            if ($current->network !== $snapshot->network || $current->query_revision !== $snapshot->query_revision
                || $current->active_query_revision !== $snapshot->active_query_revision) {
                $this->audit($current, $operator, 'query_tested', $revision, 'stale');

                return 'stale';
            }

            $current->forceFill(['query_tested_at' => now(), 'last_query_result' => $result]);
            if ($isDraft && $result === 'passed') {
                $current->forceFill([
                    'active_query' => $configuration,
                    'active_query_revision' => $revision,
                    'draft_query' => null,
                ]);
            }

            if (! $isDraft || $result === 'passed') {
                $this->updateHealth($current, $result, recover: $result === 'passed');
            }
            $current->save();
            $this->audit($current, $operator, 'query_tested', $revision, $result);

            return $result;
        }, 3);
    }

    public function setDisabled(Platform $platform, bool $disabled, User $operator): void
    {
        abort_unless($operator->hasPermission('platforms.change-status'), 403);

        DB::transaction(function () use ($platform, $disabled, $operator): void {
            $current = Platform::query()->lockForUpdate()->findOrFail($platform->id);
            if ($current->manually_disabled === $disabled) {
                return;
            }

            $current->forceFill(['manually_disabled' => $disabled])->save();
            if ($disabled) {
                PlatformFundTask::pauseForPlatform($current->id);
            }
            $this->audit($current, $operator, $disabled ? 'disabled' : 'restored');
        }, 3);
    }

    public function recordQueryOutcome(Platform $snapshot, string $result): void
    {
        DB::transaction(function () use ($snapshot, $result): void {
            $current = Platform::query()->lockForUpdate()->findOrFail($snapshot->id);
            if ($current->network !== $snapshot->network || $current->active_query_revision !== $snapshot->active_query_revision || $current->query_unhealthy_at !== null) {
                return;
            }

            $this->updateHealth($current, $result);
            $current->save();
        }, 3);
    }

    private function saveProfile(Platform $platform, array $data, User $operator, #[SensitiveParameter] ?array $draft = null): Platform
    {
        return $this->images->apply($data, 'logo_url', fn (array $profile) => $this->persistProfile($platform, $profile, $operator, $draft));
    }

    private function persistProfile(Platform $platform, array $data, User $operator, #[SensitiveParameter] ?array $draft): Platform
    {
        $position = Rule::unique('platforms', 'featured_position');
        if ($platform->exists) {
            $position->ignore($platform);
        }

        $profile = Validator::make($data, [
            'name' => 'required|string|max:120',
            'promotion_url' => 'required|string|max:2048|url:https',
            'logo_url' => PublicImages::urlRules(),
            'description' => 'nullable|string|max:2000',
            'rating' => 'required|numeric|between:1,5|decimal:0,1',
            'featured_position' => ['nullable', 'integer', 'between:1,10', $position],
        ], ['featured_position.unique' => '该精选位置已被占用。'])->validate();

        try {
            return DB::transaction(function () use ($platform, $operator, $profile, $draft): Platform {
                $action = $platform->exists ? 'profile_updated' : 'created';
                $platform->fill($profile);
                if ($draft !== null) {
                    $platform->forceFill(['draft_query' => $draft, 'query_revision' => 1]);
                }
                $platform->save();
                $this->audit($platform, $operator, $action);
                if ($draft !== null) {
                    $this->audit($platform, $operator, 'query_configured', $platform->query_revision);
                }

                return $platform->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['featured_position' => '该精选位置已被占用。']);
        }
    }

    private function updateHealth(Platform $platform, string $result, bool $recover = false): void
    {
        if (in_array($result, ['passed', 'not_found', 'ambiguous'], true)) {
            $platform->forceFill(['query_failures' => 0]);
            if ($recover) {
                $platform->forceFill(['query_unhealthy_at' => null, 'query_health_reason' => null]);
            }

            return;
        }

        $count = $result === 'transient' ? min(3, $platform->query_failures + 1) : 3;
        $platform->forceFill(['query_failures' => $count]);
        if ($count === 3) {
            $platform->forceFill([
                'query_unhealthy_at' => $platform->query_unhealthy_at ?? now(),
                'query_health_reason' => $result,
            ]);
            PlatformFundTask::pauseForPlatform($platform->id);
        }
    }

    private function audit(Platform $platform, User $operator, string $action, ?int $revision = null, ?string $result = null): void
    {
        DB::table('platform_audits')->insert([
            'platform_id' => $platform->id, 'user_id' => $operator->id, 'action' => $action,
            'query_revision' => $revision, 'result' => $result, 'created_at' => now(),
        ]);
    }
}

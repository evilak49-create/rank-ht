<?php

namespace App\Storage;

use App\Models\OssLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class OssConfiguration
{
    public function __construct(private readonly OssGateway $gateway) {}

    public function active(string $purpose): ?OssLocation
    {
        $id = DB::table('oss_settings')->where('purpose', $purpose)->value('oss_location_id');

        return $id === null ? null : OssLocation::findOrFail($id);
    }

    public function historicalPrivate(int $id): OssLocation
    {
        $original = OssLocation::query()->where('purpose', 'private')->findOrFail($id);

        return $this->sameBucket($original)->where('purpose', 'private')->latest('id')->firstOrFail();
    }

    public function save(array $input, User $operator): void
    {
        abort_unless($operator->hasPermission('system.manage-oss'), 403);
        $data = Validator::make($input, [
            'purpose' => ['required', 'in:public,private'],
            'provider' => ['required', 'in:aws,aliyun'],
            'bucket' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9-]{1,61}[a-z0-9]\z/'],
            'region' => ['required', 'string', 'regex:/\A[a-z]{2}(?:-[a-z0-9]{1,14}){1,4}\z/', 'max:32'],
            'endpoint' => ['nullable', 'string', 'max:512'],
            'public_url' => ['nullable', 'string', 'max:512'],
            'access_key' => ['required', 'string', 'max:256'],
            'secret_key' => ['required', 'string', 'max:256'],
        ])->validate();

        $endpoint = $this->httpsBase($data['endpoint'] ?? null, 'endpoint');
        if ($data['provider'] === 'aws') {
            $suffix = str_starts_with($data['region'], 'cn-') ? '.amazonaws.com.cn' : '.amazonaws.com';
            if ($endpoint !== null && $endpoint !== 'https://s3.'.$data['region'].$suffix) {
                throw ValidationException::withMessages(['endpoint' => '请输入所选 AWS 地域的 S3 服务端点。']);
            }
        } else {
            $endpoint ??= 'https://s3.oss-'.$data['region'].'.aliyuncs.com';
        }
        $public = $data['purpose'] === 'public';
        $publicUrl = $public ? $this->httpsBase($data['public_url'] ?? null, 'public_url', pathAllowed: true) : null;
        $location = new OssLocation([
            ...array_intersect_key($data, array_flip(['purpose', 'provider', 'bucket', 'region'])),
            'endpoint' => $endpoint, 'public_url' => $publicUrl, 'user_id' => $operator->id,
            'credentials' => ['key' => $data['access_key'], 'secret' => $data['secret_key']],
        ]);

        $this->assertDistinct($location);
        try {
            $this->gateway->probe($location);
        } catch (Throwable) {
            $this->audit($location, 'failed', 'storage_check_failed');
            throw ValidationException::withMessages(['bucket' => '存储验证未通过。请检查端点、访问权限和存储桶的公开访问设置。']);
        }

        DB::transaction(function () use ($location): void {
            DB::table('oss_settings')->orderBy('purpose')->lockForUpdate()->get();
            $this->assertDistinct($location);
            $location->save();
            DB::table('oss_settings')->where('purpose', $location->purpose)->update(['oss_location_id' => $location->id]);
            $this->audit($location, 'activated');
        });
    }

    private function httpsBase(?string $url, string $field, bool $pathAllowed = false): ?string
    {
        if (blank($url)) {
            return null;
        }
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if (PublicHttps::host($url) === null || isset($parts['query']) || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && (! $pathAllowed || ! preg_match('#\A(?:/[a-zA-Z0-9_~-]+)+\z#', $parts['path'])))) {
            throw ValidationException::withMessages([$field => '请输入有效的 HTTPS 域名或存储地址。']);
        }

        return $url;
    }

    private function sameBucket(OssLocation $location): Builder
    {
        return OssLocation::query()->where([
            'provider' => $location->provider, 'bucket' => $location->bucket, 'region' => $location->region,
        ]);
    }

    private function assertDistinct(OssLocation $location): void
    {
        if ($this->sameBucket($location)->where('purpose', '!=', $location->purpose)->exists()) {
            throw ValidationException::withMessages(['bucket' => '公开图片和私有凭证必须使用不同的存储桶。']);
        }
    }

    private function audit(OssLocation $location, string $status, ?string $error = null): void
    {
        DB::table('oss_audits')->insert([
            'user_id' => $location->user_id, 'oss_location_id' => $location->id,
            'purpose' => $location->purpose, 'provider' => $location->provider,
            'status' => $status, 'error' => $error, 'created_at' => now(),
        ]);
    }
}

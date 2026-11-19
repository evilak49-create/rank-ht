<?php

namespace App\Storage;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class PublicImages
{
    public const LOCAL_DISK = 'site-images';

    public const LOCAL_PREFIX = '/uploads/images/';

    public const LOCAL_FILENAME = '[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\.(?:jpg|png|webp)';

    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_KIB = 5120;

    public function __construct(private readonly OssConfiguration $configuration, private readonly OssGateway $gateway) {}

    public function apply(array $data, string $urlField, Closure $save): mixed
    {
        return $this->applyMany($data, ['image_upload' => $urlField], $save);
    }

    public static function urlRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:2048',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('~\A'.self::LOCAL_PREFIX.self::LOCAL_FILENAME.'\z~', $value)) {
                    return;
                }
                if (Validator::make(['url' => $value], ['url' => 'url:https'])->fails()) {
                    $fail('图片地址无效。');
                }
            },
        ];
    }

    public function applyMany(array $data, array $fields, Closure $save): mixed
    {
        $files = [];
        foreach ($fields as $field => $target) {
            $file = data_get($data, $field);
            Arr::forget($data, $field);
            if ($file !== null) {
                $rules = ['required', File::types(array_keys(self::MIMES))->max(self::MAX_KIB), 'dimensions:max_width=8192,max_height=8192'];
                $validator = Validator::make(['file' => $file], ['file' => $rules]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages([$field => '请上传有效的 JPG、PNG 或 WebP 图片，大小不超过 5 MB。']);
                }
                $files[$field] = [$target, $file];
            }
        }
        if ($files === []) {
            return $save($data);
        }

        $location = $this->configuration->active('public');
        $uploads = [];
        try {
            foreach ($files as $field => [$target, $file]) {
                /** @var UploadedFile $file */
                $mime = $file->getMimeType();
                $filename = Str::uuid().'.'.self::MIMES[$mime];
                $key = $location === null ? $filename : 'public-assets/'.$filename;
                $url = $location === null ? self::LOCAL_PREFIX.$filename : $this->gateway->url($location, $key);
                $uploads[] = ['object_key' => $key, 'url' => $url, 'mime' => $mime];
                $stream = $file instanceof TemporaryUploadedFile ? $file->readStream() : fopen($file->getPathname(), 'rb');
                if (! is_resource($stream)) {
                    throw ValidationException::withMessages([$field => '无法读取上传的图片。']);
                }
                try {
                    if ($location === null) {
                        if (! Storage::disk(self::LOCAL_DISK)->put($key, $stream)) {
                            throw ValidationException::withMessages([$field => '本地图片写入失败，请检查存储目录权限。']);
                        }
                    } else {
                        $this->gateway->put($location, $key, $stream, $mime);
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                data_set($data, $target, $url);
            }

            return DB::transaction(function () use ($location, $uploads, $data, $save): mixed {
                if ($location !== null) {
                    foreach ($uploads as $upload) {
                        DB::table('oss_public_images')->insert($upload + ['oss_location_id' => $location->id, 'created_at' => now()]);
                    }
                }

                return $save($data);
            });
        } catch (Throwable $exception) {
            foreach ($uploads as $upload) {
                $key = $upload['object_key'];
                try {
                    if ($location === null) {
                        Storage::disk(self::LOCAL_DISK)->delete($key);
                    } else {
                        $this->gateway->delete($location, $key);
                    }
                } catch (Throwable $cleanup) {
                    Log::warning('Public image cleanup failed.', ['location' => $location?->id, 'object_key' => $key, 'error' => $cleanup::class]);
                }
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            Log::warning('Public image could not be saved.', ['location' => $location?->id, 'error' => $exception::class]);
            throw ValidationException::withMessages([$field => '图片上传或保存失败，请重试。']);
        }
    }
}

<?php

namespace App\Storage;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class PublicImages
{
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_KIB = 5120;

    public function __construct(private readonly OssConfiguration $configuration, private readonly OssGateway $gateway) {}

    public function apply(array $data, string $urlField, Closure $save): mixed
    {
        $file = $data['image_upload'] ?? null;
        unset($data['image_upload']);
        if ($file === null) {
            return $save($data);
        }

        Validator::make(['image_upload' => $file], [
            'image_upload' => ['required', File::types(array_keys(self::MIMES))->max(self::MAX_KIB), 'dimensions:max_width=8192,max_height=8192'],
        ])->validate();
        $location = $this->configuration->active('public');
        if ($location === null) {
            throw ValidationException::withMessages(['image_upload' => '请先启用公开图片存储。']);
        }
        /** @var UploadedFile $file */
        $mime = $file->getMimeType();
        $key = 'public-assets/'.Str::uuid().'.'.self::MIMES[$mime];
        $url = $this->gateway->url($location, $key);
        $stream = $file instanceof TemporaryUploadedFile ? $file->readStream() : fopen($file->getPathname(), 'rb');
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['image_upload' => '无法读取上传的图片。']);
        }

        try {
            $this->gateway->put($location, $key, $stream, $mime);
            $data[$urlField] = $url;

            return DB::transaction(function () use ($location, $key, $url, $mime, $data, $save): mixed {
                DB::table('oss_public_images')->insert([
                    'oss_location_id' => $location->id, 'object_key' => $key, 'url' => $url, 'mime' => $mime, 'created_at' => now(),
                ]);

                return $save($data);
            });
        } catch (Throwable $exception) {
            try {
                $this->gateway->delete($location, $key);
            } catch (Throwable $cleanup) {
                Log::warning('Public image cleanup failed.', ['location' => $location->id, 'object_key' => $key, 'error' => $cleanup::class]);
            }
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            Log::warning('Public image could not be saved.', ['location' => $location->id, 'error' => $exception::class]);
            throw ValidationException::withMessages(['image_upload' => '图片上传或保存失败，请重试。']);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

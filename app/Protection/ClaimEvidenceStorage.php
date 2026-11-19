<?php

namespace App\Protection;

use App\Models\ClaimEvidence;
use App\Models\OssLocation;
use App\Storage\OssConfiguration;
use App\Storage\OssGateway;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClaimEvidenceStorage
{
    public const MAX_FILES = 6;

    public const MAX_KIB = 5120;

    private const LOCAL = 'claim-evidence-local';

    private const CLOUD = 'claim-evidence-s3';

    public function __construct(private readonly OssConfiguration $configuration, private readonly OssGateway $gateway) {}

    public function uploadDisk(): string
    {
        $location = $this->configuration->active('private');
        if ($location !== null) {
            return 'oss:'.$location->id;
        }

        if (filled(config('filesystems.disks.'.self::CLOUD.'.bucket'))) {
            return self::CLOUD;
        }

        abort_unless(app()->environment(['local', 'testing']), 503, '申诉凭证存储暂不可用。');

        return self::LOCAL;
    }

    public function put(string $disk, string $key, UploadedFile $file, string $mime): void
    {
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the uploaded evidence.');
        }

        try {
            $location = $this->location($disk);
            if ($location !== null) {
                $this->gateway->put($location, $key, $stream, $mime);

                return;
            }

            $storage = Storage::disk($disk);
            if ($storage instanceof AwsS3V3Adapter) {
                // The private bucket policy controls access, including on AWS buckets with ACLs disabled.
                $storage->getClient()->putObject([
                    'Bucket' => config('filesystems.disks.'.$disk.'.bucket'), 'Key' => $key,
                    'Body' => $stream, 'ContentType' => $mime,
                ]);
            } elseif (! $storage->put($key, $stream)) {
                throw new RuntimeException('Unable to store claim evidence.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function cleanUp(string $disk, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        try {
            $location = $this->location($disk);
            if ($location !== null) {
                foreach ($keys as $key) {
                    $this->gateway->delete($location, $key);
                }
            } elseif (! Storage::disk($disk)->delete($keys)) {
                throw new RuntimeException('Unable to clean up uncommitted evidence.');
            }
        } catch (Throwable $exception) {
            Log::warning('Claim evidence cleanup failed.', ['disk' => $disk, 'keys' => $keys, 'error' => $exception::class]);
        }
    }

    public function show(ClaimEvidence $evidence): StreamedResponse
    {
        $location = $this->location($evidence->disk);
        abort_unless($location !== null || in_array($evidence->disk, [self::LOCAL, self::CLOUD], true), 404);
        try {
            $stream = $location === null
                ? Storage::disk($evidence->disk)->readStream($evidence->object_key)
                : $this->gateway->read($location, $evidence->object_key);
        } catch (Throwable $exception) {
            Log::warning('Unable to read claim evidence.', ['evidence_id' => $evidence->id, 'error' => $exception::class]);
            abort(503, '申诉凭证暂不可读取。');
        }
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, headers: [
            'Content-Type' => $evidence->mime,
            'Content-Length' => (string) $evidence->size_bytes,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function location(string $disk): ?OssLocation
    {
        return str_starts_with($disk, 'oss:') ? $this->configuration->historicalPrivate((int) substr($disk, 4)) : null;
    }
}

<?php

namespace App\Storage;

use App\Models\OssLocation;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Str;
use RuntimeException;
use WeakMap;

class OssGateway
{
    /** @var WeakMap<OssLocation, S3Client> */
    private WeakMap $clients;

    public function __construct(private readonly PublicHttps $https)
    {
        $this->clients = new WeakMap;
    }

    public function probe(OssLocation $location): void
    {
        $public = $location->purpose === 'public';
        $key = ($public ? 'public-assets/' : 'protection-claims/').'.check-'.Str::uuid().'.txt';
        $token = Str::random(32);

        try {
            $this->put($location, $key, $token, 'text/plain');
            $result = $this->client($location)->getObject(['Bucket' => $location->bucket, 'Key' => $key]);
            if ((string) $result['Body'] !== $token) {
                throw new RuntimeException('Storage verification returned different content.');
            }

            $request = new Request('HEAD', $this->url($location, $key));
            $response = ($this->https)($request, ['connect_timeout' => 5, 'timeout' => 12])->wait();
            $status = $response->getStatusCode();
            if ($public ? ($status !== 200 || $response->getHeaderLine('Content-Length') !== (string) strlen($token)) : ! in_array($status, [403, 404], true)) {
                throw new RuntimeException('Storage visibility verification failed.');
            }
        } finally {
            $this->delete($location, $key);
        }
    }

    /** @param resource|string $body */
    public function put(OssLocation $location, string $key, mixed $body, string $mime): void
    {
        $this->client($location)->putObject([
            'Bucket' => $location->bucket, 'Key' => $key, 'Body' => $body, 'ContentType' => $mime,
        ]);
    }

    public function read(OssLocation $location, string $key): mixed
    {
        $result = $this->client($location)->getObject([
            'Bucket' => $location->bucket, 'Key' => $key, '@http' => ['stream' => true],
        ]);

        return $result['Body']->detach();
    }

    public function delete(OssLocation $location, string $key): void
    {
        $this->client($location)->deleteObject(['Bucket' => $location->bucket, 'Key' => $key]);
    }

    public function url(OssLocation $location, string $key): string
    {
        if ($location->purpose === 'public' && $location->public_url !== null) {
            return $location->public_url.'/'.$key;
        }
        if ($location->bucketEndpoint()) {
            return $location->endpoint.'/'.$key;
        }

        return $this->client($location)->getObjectUrl($location->bucket, $key);
    }

    private function client(OssLocation $location): S3Client
    {
        return $this->clients[$location] ??= $this->createClient($location);
    }

    private function createClient(OssLocation $location): S3Client
    {
        $options = [
            'version' => 'latest', 'region' => $location->region,
            'credentials' => $location->credentials,
            'bucket_endpoint' => $location->bucketEndpoint(),
            'http_handler' => $this->https,
            'http' => ['connect_timeout' => 5, 'timeout' => 12, 'allow_redirects' => false],
            'retries' => 0,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'use_aws_shared_config_files' => false,
        ];
        if ($location->endpoint !== null) {
            $options['endpoint'] = $location->endpoint;
        }

        return new S3Client($options);
    }
}

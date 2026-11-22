<?php

namespace Tests\Feature;

use App\Content\Publishing;
use App\Filament\Pages\OssSettings;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\AdminRole;
use App\Models\ClaimEvidence;
use App\Models\OssLocation;
use App\Models\Product;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Protection\ClaimEvidenceStorage;
use App\Shop\PhysicalShop;
use App\Storage\OssConfiguration;
use App\Storage\OssGateway;
use App\Storage\PublicHttps;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class OssSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_configuration_activates_and_credentials_stay_out_of_the_panel_and_audit(): void
    {
        $staff = $this->administrator([]);
        $this->actingAs($staff, 'web')->get('/admin/oss-settings')->assertForbidden();
        $staff->roles()->attach(AdminRole::create(['name' => '存储管理', 'permissions' => ['system.manage-oss']]));
        $this->get('/admin/oss-settings')->assertOk();

        $gateway = Mockery::mock(OssGateway::class);
        $gateway->shouldReceive('probe')->once()->withArgs(fn ($location) => $location->bucket === 'verified-private')->andReturnNull();
        $gateway->shouldReceive('probe')->once()->withArgs(fn ($location) => $location->bucket === 'failed-private')
            ->andThrow(new RuntimeException('test-secret-plaintext'));
        $this->app->instance(OssGateway::class, $gateway);

        $input = [
            'purpose' => 'private', 'provider' => 'aws', 'bucket' => 'verified-private', 'region' => 'us-east-1',
            'access_key' => 'test-access-key', 'secret_key' => 'test-secret-plaintext',
        ];
        Livewire::test(OssSettings::class)->fillForm($input)->call('save')->assertHasNoFormErrors();
        Livewire::test(OssSettings::class)->fillForm([...$input, 'bucket' => 'failed-private'])
            ->call('save')->assertHasFormErrors(['bucket'])->assertDontSee('test-secret-plaintext');
        $this->get('/admin/oss-settings')->assertOk()->assertDontSee('test-secret-plaintext')->assertDontSee('test-access-key');

        $this->assertDatabaseCount('oss_locations', 1);
        $stored = DB::table('oss_locations')->sole();
        $this->assertStringNotContainsString('test-secret-plaintext', $stored->credentials);
        $this->assertDatabaseHas('oss_locations', ['bucket' => 'verified-private', 'purpose' => 'private']);
        $this->assertDatabaseHas('oss_audits', ['status' => 'activated', 'user_id' => $staff->id]);
        $this->assertDatabaseHas('oss_audits', ['status' => 'failed', 'user_id' => $staff->id]);
        $this->assertStringNotContainsString('test-secret-plaintext', DB::table('oss_audits')->get()->toJson());
    }

    public function test_bucket_switch_preserves_historical_evidence_and_same_bucket_rotation_updates_credentials(): void
    {
        $staff = $this->administrator(['system.manage-oss']);
        $gateway = Mockery::mock(OssGateway::class);
        $gateway->shouldReceive('probe')->times(3);
        $gateway->shouldReceive('put')->once()->withArgs(fn ($location, $key) => $location->bucket === 'original-private' && $key === 'image');
        $gateway->shouldReceive('read')->once()->withArgs(fn ($location, $key) => $location->bucket === 'original-private'
            && $location->credentials['key'] === 'rotated-key' && $key === 'image')->andReturnUsing(function () {
                $stream = fopen('php://temp', 'w+');
                fwrite($stream, 'image');
                rewind($stream);

                return $stream;
            });
        $this->app->instance(OssGateway::class, $gateway);
        $configuration = app(OssConfiguration::class);
        $configuration->save($this->input('private', 'original-private'), $staff);
        $storage = app(ClaimEvidenceStorage::class);
        $oldDisk = $storage->uploadDisk();
        $storage->put($oldDisk, 'image', $this->image(), 'image/png');
        $configuration->save([...$this->input('private', 'original-private'), 'access_key' => 'rotated-key'], $staff);
        $configuration->save($this->input('private', 'replacement-private'), $staff);
        $this->assertNotSame($oldDisk, $storage->uploadDisk());
        $response = $storage->show(new ClaimEvidence([
            'disk' => $oldDisk, 'object_key' => 'image', 'mime' => 'image/png', 'size_bytes' => 5,
        ]));
        ob_start();
        $response->sendContent();
        $this->assertSame('image', ob_get_clean());
        try {
            $configuration->save($this->input('public', 'original-private'), $staff);
            $this->fail('Public and private storage must have different buckets.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bucket', $exception->errors());
        }
    }

    public function test_public_images_use_the_verified_location_for_platforms_banners_and_products(): void
    {
        $staff = $this->administrator(['system.manage-oss', 'platforms.edit', 'content.manage', 'shop.manage-products']);
        $gateway = Mockery::mock(OssGateway::class);
        $gateway->shouldReceive('probe')->once();
        $gateway->shouldReceive('url')->times(4)->andReturnUsing(fn ($location, $key) => $location->public_url.'/'.$key);
        $gateway->shouldReceive('put')->times(4)->withArgs(fn ($location, $key, $stream, $mime) => $location->bucket === 'public-images'
            && str_starts_with($key, 'public-assets/') && is_resource($stream) && $mime === 'image/png');
        $gateway->shouldReceive('delete')->once();
        $this->app->instance(OssGateway::class, $gateway);
        app(OssConfiguration::class)->save([
            ...$this->input('public', 'public-images'), 'provider' => 'aliyun', 'region' => 'cn-hangzhou',
            'public_url' => 'https://cdn.example.test/images',
        ], $staff);

        $platform = app(PlatformAdministration::class)->create([
            'name' => 'Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.0', 'image_upload' => $this->image(),
        ], $staff);
        $banner = app(Publishing::class)->banner(null, [
            'name' => 'Banner', 'image_upload' => $this->image(), 'target_type' => 'home', 'sort_order' => 0, 'active' => true,
        ], $staff);
        $this->actingAs($staff, 'web');
        Livewire::test(ListProducts::class)->mountAction('create_product')
            ->setActionData(['name' => 'Product', 'price_brl' => '25.75', 'is_active' => true])
            ->set('mountedActions.0.data.image_upload', [$this->image()])
            ->callMountedAction()->assertHasNoActionErrors();
        $images = [$platform->logo_url, $banner->image_url, Product::query()->sole()->image_url];
        $this->assertCount(3, array_unique($images));
        foreach ($images as $url) {
            $this->assertStringStartsWith('https://cdn.example.test/images/public-assets/', $url);
            $this->assertDatabaseHas('oss_public_images', ['url' => $url]);
        }
        try {
            app(PhysicalShop::class)->saveProduct(null, [
                'name' => 'Invalid product', 'points_price' => 0, 'is_active' => true, 'image_upload' => $this->image(),
            ], $staff);
            $this->fail('The invalid product should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('points_price', $exception->errors());
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('oss_public_images', 3);
    }

    public function test_real_gateway_checks_signed_storage_and_unsigned_visibility_for_both_providers(): void
    {
        $requests = [];
        $probe = '';
        $transport = Mockery::mock(PublicHttps::class);
        $transport->shouldReceive('__invoke')->times(12)->andReturnUsing(function (RequestInterface $request) use (&$requests, &$probe): FulfilledPromise {
            $method = $request->getMethod();
            $host = $request->getUri()->getHost();
            $requests[] = [$method, $host, $request->hasHeader('Authorization'), $request->getUri()->getPath()];
            if ($method === 'PUT') {
                $probe = (string) $request->getBody();
            }
            $response = match ($method) {
                'PUT' => new Response(200),
                'GET' => new Response(200, [], $probe),
                'HEAD' => $host === 'cdn.example.test' ? new Response(200, ['Content-Length' => (string) strlen($probe)]) : new Response(403),
                'DELETE' => new Response(204),
            };

            return new FulfilledPromise($response);
        });
        $gateway = new OssGateway($transport);
        $credentials = ['key' => 'test-key', 'secret' => 'test-secret'];
        $gateway->probe(new OssLocation([
            'purpose' => 'private', 'provider' => 'aws', 'bucket' => 'private-test', 'region' => 'us-east-1', 'credentials' => $credentials,
        ]));
        $gateway->probe(new OssLocation([
            'purpose' => 'public', 'bucket' => 'public-test', 'provider' => 'aliyun', 'region' => 'cn-hangzhou', 'credentials' => $credentials,
            'endpoint' => 'https://s3.oss-cn-hangzhou.aliyuncs.com',
            'public_url' => 'https://cdn.example.test',
        ]));
        $gateway->probe(new OssLocation([
            'purpose' => 'private', 'bucket' => 'private-alias', 'provider' => 'aliyun', 'region' => 'cn-hangzhou', 'credentials' => $credentials,
            'endpoint' => 'https://private.example.test',
        ]));

        $this->assertSame(array_merge(...array_fill(0, 3, ['PUT', 'GET', 'HEAD', 'DELETE'])), array_column($requests, 0));
        $this->assertStringEndsWith('.amazonaws.com', $requests[0][1]);
        $this->assertStringEndsWith('.aliyuncs.com', $requests[4][1]);
        $this->assertSame('cdn.example.test', $requests[6][1]);
        $this->assertSame('private.example.test', $requests[8][1]);
        $this->assertStringStartsWith('/protection-claims/', $requests[8][3]);
        $this->assertSame(array_merge(...array_fill(0, 3, [true, true, false, true])), array_column($requests, 2));
    }

    public function test_configuration_rejects_local_or_mismatched_service_endpoints(): void
    {
        $staff = $this->administrator(['system.manage-oss']);
        $gateway = Mockery::mock(OssGateway::class)->shouldNotReceive('probe')->getMock();
        $this->app->instance(OssGateway::class, $gateway);
        $configuration = app(OssConfiguration::class);
        foreach (['https://127.0.0.1', 'http://storage.example.test', 'https://s3.eu-west-1.amazonaws.com'] as $url) {
            try {
                $configuration->save([...$this->input('private', 'private-test'), 'endpoint' => $url], $staff);
                $this->fail('The unsupported endpoint should be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('endpoint', $exception->errors());
            }
        }
        $this->assertDatabaseEmpty('oss_locations');
    }

    private function administrator(array $permissions): User
    {
        $staff = User::factory()->create();
        $staff->forceFill(['panel_enabled' => true])->save();
        $staff->roles()->attach(AdminRole::create(['name' => 'Test OSS role', 'permissions' => $permissions]));

        return $staff;
    }

    private function input(string $purpose, string $bucket): array
    {
        return [
            'purpose' => $purpose, 'provider' => 'aws', 'bucket' => $bucket, 'region' => 'us-east-1',
            'access_key' => 'test-access-key', 'secret_key' => 'test-secret-key',
        ];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('image.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+bWZkAAAAASUVORK5CYII=',
        ));
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Resources\ProtectionTermResource\Pages\ListProtectionTerms;
use App\Models\AdminRole;
use App\Models\ClaimEvidence;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\ProtectionTerm;
use App\Models\User;
use App\Protection\ClaimEvidenceStorage;
use App\Storage\OssConfiguration;
use App\Storage\OssGateway;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProtectionClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_binding_can_file_against_a_disabled_platform_and_evidence_is_private(): void
    {
        $disk = Storage::fake('claim-evidence-local');
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $this->getJson('/api/v1/protection/bindings')->assertOk()
            ->assertJsonPath('data.0.protection_status', 'inactive')
            ->assertJsonPath('data.0.masked_platform_account', '****4567');
        $payload = $this->payload($binding);
        $claim = $this->post('/api/v1/protection/claims', $payload, ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.amount_cents', 12035)
            ->assertJsonPath('data.evidence.1.position', 2)->json('data');
        $this->post('/api/v1/protection/claims', $this->payload($binding, $payload['request_id']), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $claim['id']);
        $this->assertDatabaseCount('protection_claims', 1);
        $this->assertCount(2, $disk->allFiles());

        $url = $claim['evidence'][0]['url'];
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $other = $this->member('11912345679');
        Auth::forgetGuards();
        $this->actingAs($other, 'member')->getJson('/api/v1/protection/claims/'.$claim['id'])->assertNotFound();
        $this->get($url)->assertNotFound();
        $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])->assertNotFound();
        Auth::forgetGuards();
        $this->actingAs($member, 'member');
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_admin_cases_are_read_only_and_evidence_requires_current_permissions(): void
    {
        Storage::fake('claim-evidence-local');
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $claim = $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $this->actingAs($operator, 'web');
        $this->get('/admin/protection-claims')->assertForbidden();
        $role = AdminRole::create(['name' => '案件查看', 'permissions' => ['protection.claims.view']]);
        $operator->roles()->attach($role);
        $this->get('/admin/protection-claims')->assertOk();
        $this->get('/admin/protection-claims/'.$claim['id'])->assertOk()
            ->assertSee('提现未收到')->assertSeeInOrder(['查看图片 1', '查看图片 2']);
        $this->get('/admin/protection-claims/'.$claim['id'].'/edit')->assertNotFound();
        $evidence = ClaimEvidence::findOrFail($claim['evidence'][0]['id']);
        $url = $evidence->accessUrl('admin');
        $this->get($url)->assertOk();
        $operator->roles()->detach($role);
        $this->get($url)->assertForbidden();
    }

    public function test_case_outcome_is_visible_to_member_without_touching_wallet(): void
    {
        Storage::fake('claim-evidence-local');
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $id = $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.case_status', 'pending')->json('data.id');
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '申诉处理', 'permissions' => ['protection.claims.resolve']]));
        app(\App\Protection\ProtectionClaims::class)->resolve(\App\Models\ProtectionClaim::findOrFail($id), $operator, 'resolved');
        $this->getJson('/api/v1/protection/claims/'.$id)->assertOk()->assertJsonPath('data.case_status', 'resolved');
        $this->assertSame(0, $member->refresh()->available_balance_cents);
        $this->assertDatabaseCount('cash_balance_entries', 0);
        app(\App\Protection\ProtectionClaims::class)->resolve(\App\Models\ProtectionClaim::findOrFail($id), $operator, 'resolved');
        $this->assertDatabaseCount('admin_audits', 1);
    }

    public function test_failed_or_invalid_uploads_leave_no_case_or_files(): void
    {
        $disk = Storage::fake('claim-evidence-local');
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $payload = $this->payload($binding);
        $payload['evidence'][1] = UploadedFile::fake()->createWithContent('fake.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->post('/api/v1/protection/claims', $payload, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence.1');

        $storage = Mockery::mock(ClaimEvidenceStorage::class, [app(OssConfiguration::class), app(OssGateway::class)])->makePartial();
        $storage->shouldReceive('put')->once()->withArgs(fn ($disk, $key, $file, $mime) => $file->getClientOriginalName() === 'first.png')->passthru();
        $storage->shouldReceive('put')->once()->withArgs(fn ($disk, $key, $file, $mime) => $file->getClientOriginalName() === 'second.png')
            ->andThrow(new RuntimeException('storage timeout'));
        $this->app->instance(ClaimEvidenceStorage::class, $storage);
        $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])
            ->assertServiceUnavailable();
        $this->assertDatabaseEmpty('protection_claims');
        $this->assertDatabaseEmpty('claim_evidence');
        $this->assertSame([], $disk->allFiles());
    }

    public function test_unpublished_terms_return_an_empty_response_for_a_valid_platform_and_owned_binding(): void
    {
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $binding->platform->forceFill(['manually_disabled' => false, 'active_query_revision' => 1])->save();
        $publicUrl = '/api/v1/platforms/'.$binding->platform_id.'/protection-terms';
        $memberUrl = '/api/v1/protection/bindings/'.$binding->id.'/terms';

        $this->getJson($publicUrl)->assertOk()->assertExactJson(['data' => null]);
        $this->getJson('/api/v1/platforms/999999/protection-terms')->assertNotFound();

        $this->actingAs($this->member('11912345679'), 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($memberUrl)->assertNotFound();

        Auth::forgetGuards();
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($memberUrl)->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_global_terms_render_for_every_platform_with_only_the_viewers_own_bound_account(): void
    {
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $platform = $binding->platform;
        $platform->forceFill(['manually_disabled' => false, 'active_query_revision' => 1, 'guarantee_amount_brl' => '1234.50'])->save();
        $other = $this->member('11912345679');
        $otherBinding = $this->binding($other);
        $otherBinding->platform->forceFill(['name' => '另一平台', 'manually_disabled' => false, 'active_query_revision' => 1])->save();
        $operator = User::factory()->create(['panel_enabled' => true]);
        $operator->roles()->attach(AdminRole::create(['name' => '保障运营', 'permissions' => ['protection.terms.manage']]));
        $this->actingAs($operator, 'web');
        Livewire::test(ListProtectionTerms::class)->callAction('publish', [
            'title' => '【平台名称】保障条款',
            'body' => '【平台名称】｜【保障金额】｜【会员账号】｜【保障状态】',
        ])->assertHasNoActionErrors();

        $version = ProtectionTerm::query()->whereNull('platform_id')->sole();
        ProtectionTerm::create([
            'platform_id' => $platform->id, 'user_id' => $operator->id,
            'title' => '旧版单平台条款', 'body' => '旧版条款正文',
        ]);
        $url = '/api/v1/platforms/'.$platform->id.'/protection-terms';
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $version->id)
            ->assertJsonPath('data.title', '保障平台保障条款')
            ->assertJsonPath('data.body', '保障平台｜R$ 1.234,50｜—｜—');
        $this->getJson('/api/v1/platforms/'.$otherBinding->platform_id.'/protection-terms')
            ->assertOk()->assertJsonPath('data.id', $version->id)->assertJsonPath('data.body', '另一平台｜—｜—｜—');

        Auth::forgetGuards();
        $this->actingAs($member->refresh(), 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.body', '保障平台｜R$ 1.234,50｜1234567｜保障中');
        $this->getJson('/api/v1/protection/bindings/'.$binding->id.'/terms')
            ->assertOk()->assertJsonPath('data.body', '保障平台｜R$ 1.234,50｜1234567｜保障中');
        $this->getJson('/api/v1/platforms/'.$otherBinding->platform_id.'/protection-terms')
            ->assertOk()->assertJsonPath('data.body', '另一平台｜—｜—｜—');
        $this->getJson('/api/v1/protection/bindings/'.$otherBinding->id.'/terms')->assertNotFound();

        Auth::forgetGuards();
        $this->actingAs($other->refresh(), 'member')->withSession(['member_session_version' => 0]);
        $this->getJson('/api/v1/protection/bindings/'.$otherBinding->id.'/terms')
            ->assertOk()->assertJsonPath('data.body', '另一平台｜—｜1234567｜未生效');

        Auth::forgetGuards();
        $this->actingAs($member->refresh(), 'member')->withSession(['member_session_version' => 0]);
        $version->update(['body' => '{{平台名称}}｜{{保障金额}}｜{{会员账号}}']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.body', '保障平台｜R$ 1.234,50｜1234567');

        $this->withSession(['member_session_version' => 1])->getJson($url)
            ->assertOk()->assertJsonPath('data.body', '保障平台｜R$ 1.234,50｜—');
    }

    public function test_terms_have_versions_and_case_keeps_the_original_binding_snapshot(): void
    {
        Storage::fake('claim-evidence-local');
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $operator = User::factory()->create();
        $operator->forceFill(['panel_enabled' => true])->save();
        $operator->roles()->attach(AdminRole::create([
            'name' => '保障运营', 'permissions' => ['protection.terms.manage', 'protection.eligibility.manage'],
        ]));
        $this->actingAs($operator, 'web');
        $this->get('/admin/protection-terms')->assertOk();
        $this->get('/admin/protection-eligibility')->assertNotFound();
        $platform = $binding->platform;
        $publish = ['title' => '保障说明', 'body' => '第一版条款'];
        Livewire::test(ListProtectionTerms::class)->callAction('publish', $publish)->assertHasNoActionErrors();
        $platform->forceFill(['guarantee_amount_brl' => 100])->save();

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $url = '/api/v1/protection/bindings/'.$binding->id.'/terms';
        $version = $this->getJson($url)->assertOk()->assertJsonPath('data.body', '第一版条款')->json('data.id');
        $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.protection_status', 'active')->assertJsonPath('data.term_version_id', $version);

        $this->actingAs($operator, 'web');
        Livewire::test(ListProtectionTerms::class)->callAction('publish', [...$publish, 'body' => '第二版条款'])
            ->assertHasNoActionErrors();
        $this->getJson($url)->assertOk()->assertJsonPath('data.body', '第二版条款');
        $this->assertDatabaseHas('protection_claims', ['protection_term_id' => $version]);
        $publicUrl = "/api/v1/platforms/{$platform->id}/protection-terms";
        $this->getJson($publicUrl)->assertNotFound();
        $platform->forceFill(['manually_disabled' => false, 'active_query_revision' => 1])->save();
        $this->getJson($publicUrl)->assertOk()->assertJsonPath('data.body', '第二版条款');
    }

    public function test_private_cloud_upload_and_download_use_the_configured_bucket(): void
    {
        $handler = new MockHandler([new Result, new Result, new Result(['Body' => Utils::streamFor('evidence')])]);
        config([
            'filesystems.disks.claim-evidence-s3.bucket' => 'private-test-bucket',
            'filesystems.disks.claim-evidence-s3.key' => 'test-key',
            'filesystems.disks.claim-evidence-s3.secret' => 'test-secret',
            'filesystems.disks.claim-evidence-s3.handler' => $handler,
        ]);
        $member = $this->member('11912345678');
        $binding = $this->binding($member);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $evidence = $this->post('/api/v1/protection/claims', $this->payload($binding), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.evidence.1');

        $this->assertSame('PutObject', $handler->getLastCommand()->getName());
        $this->assertSame('private-test-bucket', $handler->getLastCommand()['Bucket']);
        $this->assertNull($handler->getLastRequest()->getHeaderLine('x-amz-acl') ?: null);
        $this->assertSame(ClaimEvidence::findOrFail($evidence['id'])->object_key, $handler->getLastCommand()['Key']);
        $download = $this->get($evidence['url'])->assertOk();
        $this->assertSame('evidence', $download->streamedContent());
        $this->assertSame('GetObject', $handler->getLastCommand()->getName());
    }

    private function member(string $phone): Member
    {
        return Member::create(['phone' => $phone, 'password' => 'member-password']);
    }

    private function binding(Member $member): PlatformBinding
    {
        $platform = Platform::create(['name' => '保障平台', 'promotion_url' => 'https://example.test', 'rating' => '4.0']);
        $platform->forceFill(['manually_disabled' => true])->save();
        $binding = PlatformBinding::create([
            'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_account_id' => '1234567',
            'query_phone' => $member->phone,
            'baseline_deposit_cents' => 0, 'baseline_valid_bet_cents' => 0, 'baseline_observed_at' => now(),
        ]);
        $binding->forceFill(['protection_status' => 'inactive'])->save();

        return $binding;
    }

    private function payload(PlatformBinding $binding, ?string $requestId = null): array
    {
        return [
            'request_id' => $requestId ?? (string) Str::uuid(), 'binding_id' => $binding->id,
            'amount' => '120.35', 'withdrawn_at' => now()->subHour()->toIso8601String(), 'description' => '提现未收到',
            'evidence' => [$this->png('first'), $this->png('second')],
        ];
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name.'.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+bWZkAAAAASUVORK5CYII=',
        ));
    }
}

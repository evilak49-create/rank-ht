<?php

namespace Tests\Feature;

use App\Filament\Resources\PlatformResource\Pages\ListPlatforms;
use App\Jobs\GeneratePlatformReviewBatch;
use App\Models\AdminRole;
use App\Models\Platform;
use App\Models\PlatformReviewBatch;
use App\Models\User;
use App\Platforms\PlatformReviewBatches;
use App\SupportDesk\AiConfiguration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformReviewBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batches_append_once_and_publish_disclosed_reviews_without_ratings(): void
    {
        [$operator, $platform, $service] = $this->fixture();
        $token = (string) Str::uuid();
        $batch = $service->submit($platform, $operator, 10, $token);
        $this->assertSame($batch->id, $service->submit($platform, $operator, 10, $token)->id);
        $batch->forceFill([
            'status' => 'processing', 'attempts' => 1, 'lease_key' => (string) Str::uuid(), 'lease_until' => now()->addMinute(),
        ])->save();
        $service->process($batch->id);
        $this->assertDatabaseEmpty('platform_reviews');
        $this->travel(2)->minutes();
        $service->process($batch->id);
        $service->process($batch->id);
        $this->assertSame('succeeded', $batch->refresh()->status);
        $this->assertSame(2, $batch->attempts);
        $this->assertDatabaseCount('platform_reviews', 10);
        $this->getJson('/api/v1/platforms/'.$platform->id.'/reviews')->assertNotFound();

        $second = $service->submit($platform, $operator, 10, (string) Str::uuid());
        $service->process($second->id);
        $this->assertDatabaseCount('platform_reviews', 20);
        $platform->forceFill(['active_query_revision' => 1])->save();
        $this->getJson('/api/v1/platforms/'.$platform->id.'/reviews')->assertOk()
            ->assertJsonPath('data.0.source', 'ai')->assertJsonPath('data.0.rating', null)
            ->assertJsonPath('data.0.display_name', '119****1010 · AI 生成')
            ->assertJsonPath('data.0.body', 'A reputação e a estabilidade merecem atenção. Variação 10.');
        $this->getJson('/api/v1/platforms/'.$platform->id)->assertOk()->assertJsonPath('data.review_count', 20)
            ->assertJsonPath('data.member_review_count', 0)->assertJsonPath('data.rating', '4.8');
        Http::assertSentCount(3);
    }

    public function test_invalid_ai_reviews_fail_the_entire_batch(): void
    {
        [$operator, $platform, $service] = $this->fixture(result: ['items' => [
            ['display_name' => '11987654321', 'body' => 'A estabilidade merece atenção.'],
        ]]);
        $batch = $service->submit($platform, $operator, 1, (string) Str::uuid());
        $service->process($batch->id);
        $this->assertSame('failed', $batch->refresh()->status);
        $this->assertSame('invalid_reviews', $batch->failure_code);
        $this->assertDatabaseEmpty('platform_reviews');
    }

    public function test_an_operator_can_generate_one_review_for_a_platform_without_a_description(): void
    {
        [$operator, $platform, $service] = $this->fixture(result: ['items' => [
            ['display_name' => '219****4321', 'body' => 'Convém avaliar a reputação e a estabilidade antes de usar.'],
        ]]);
        $platform->forceFill(['description' => null])->save();
        $this->actingAs($operator, 'web');
        Livewire::test(ListPlatforms::class)->callAction(TestAction::make('generate_descriptions')->table($platform), ['quantity' => 1])
            ->assertHasNoActionErrors();

        $batch = PlatformReviewBatch::query()->sole();
        $service->process($batch->id);
        $this->assertSame('succeeded', $batch->refresh()->status);
        $this->assertDatabaseHas('platform_reviews', ['source' => 'ai', 'member_id' => null, 'display_name' => '219****4321 · AI 生成']);
        $this->assertDatabaseCount('platform_reviews', 1);
    }

    public function test_panel_queues_the_batch_and_worker_catches_a_source_edit(): void
    {
        [$operator, $platform, $service] = $this->fixture();
        $this->actingAs($operator, 'web')->get('/admin/platform-review-batches')->assertOk();
        $action = TestAction::make('generate_descriptions')->table($platform);
        Livewire::test(ListPlatforms::class)->assertSee('生成评价')->callAction($action, ['quantity' => 31])
            ->assertHasActionErrors(['quantity']);
        Livewire::test(ListPlatforms::class)->callAction($action, ['quantity' => 10])
            ->assertHasNoActionErrors();
        $batch = PlatformReviewBatch::query()->sole();
        Queue::fake();
        $this->artisan('platform-reviews:dispatch')->assertSuccessful();
        Queue::assertPushed(GeneratePlatformReviewBatch::class, fn (GeneratePlatformReviewBatch $job): bool => $job->batchId === $batch->id);

        $platform->forceFill(['description' => 'Edited description.'])->save();
        (new GeneratePlatformReviewBatch($batch->id))->handle($service);
        $this->assertSame('source_changed', $batch->refresh()->failure_code);
        $this->assertDatabaseEmpty('platform_reviews');
        $this->get('/admin/platform-review-batches')->assertOk()->assertSee('生成期间平台资料发生变更');
    }

    private function fixture(?array $result = null): array
    {
        $staff = User::factory()->create();
        $staff->forceFill(['panel_enabled' => true])->save();
        $staff->roles()->attach(AdminRole::create(['name' => 'AI content', 'permissions' => [
            'platforms.view', 'platforms.generate-reviews', 'system.manage-ai',
        ]]));
        $platform = Platform::create([
            'name' => 'Test Platform', 'promotion_url' => 'https://example.test', 'rating' => '4.8',
            'description' => implode("\n", array_map(fn (int $number): string => 'Plataforma: documento público '.$number.'.', range(1, 20))),
        ]);
        Http::preventStrayRequests();
        $selection = json_encode($result ?? ['items' => array_map(fn (int $number): array => [
            'display_name' => '119****'.(1000 + $number),
            'body' => 'A reputação e a estabilidade merecem atenção. Variação '.$number.'.',
        ], range(1, 10))], JSON_THROW_ON_ERROR);
        Http::fake(['https://api.deepseek.com/chat/completions' => Http::sequence()
            ->push($this->reply('OK'))->push($this->reply($selection))->push($this->reply($selection))]);
        app(AiConfiguration::class)->save(['model' => 'deepseek-flash', 'api_key' => 'test-only-key'], $staff);

        return [$staff, $platform, app(PlatformReviewBatches::class)];
    }

    private function reply(string $content): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]]];
    }
}

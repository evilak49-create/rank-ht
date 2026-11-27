<?php

namespace Tests\Feature;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberRecordDateRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_range_limits_pagination_to_sao_paulo_dates_and_preserves_source(): void
    {
        $url = '/api/v1/cash-wallet/entries?source=rebate&date_from=2026-11-22&date_to=2026-11-22';
        $this->getJson($url)->assertUnauthorized();

        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $other = Member::create(['phone' => '21912345678', 'password' => 'member-password']);
        foreach ([
            [$member->id, 'rebate', '2026-11-22 02:59:59'],
            [$member->id, 'rebate', '2026-11-22 03:00:00'],
            [$member->id, 'rebate', '2026-11-23 02:59:59'],
            [$member->id, 'rebate', '2026-11-23 03:00:00'],
            [$member->id, 'withdrawal', '2026-11-22 12:00:00'],
            [$other->id, 'rebate', '2026-11-22 12:00:00'],
            ...array_fill(0, 21, [$member->id, 'rebate', '2026-11-24 10:00:00']),
        ] as $index => [$memberId, $source, $time]) {
            DB::table('cash_balance_entries')->insert([
                'member_id' => $memberId, 'source' => $source, 'source_id' => $index + 1,
                'amount_cents' => $index + 1, 'balance_after_cents' => 1000, 'created_at' => $time,
            ]);
        }

        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 2)->assertJsonPath('last_page', 1)
            ->assertJsonPath('data.0.amount_cents', 3)->assertJsonPath('data.1.amount_cents', 2);
        $this->getJson('/api/v1/cash-wallet/entries?source=rebate&date_to=2026-11-22')
            ->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/v1/cash-wallet/entries?source=rebate&date_from=2026-11-23')
            ->assertOk()->assertJsonPath('total', 22);
        $this->getJson('/api/v1/cash-wallet/entries?source=rebate')->assertOk()->assertJsonPath('total', 25);
    }

    public function test_invalid_or_reversed_dates_return_validation_errors(): void
    {
        $member = Member::create(['phone' => '11912345678', 'password' => 'member-password']);
        $this->actingAs($member, 'member')->withSession(['member_session_version' => 0]);

        $path = '/api/v1/withdrawal-history?';
        foreach (['date_from=2026-02-29', 'date_from=2026-2-01', 'date_from[]=2026-02-01', 'date_from='] as $query) {
            $this->getJson($path.$query)->assertUnprocessable()->assertJsonValidationErrors('date_from');
        }
        $this->getJson($path.'date_from=2026-02-10&date_to=2026-02-09')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }
}

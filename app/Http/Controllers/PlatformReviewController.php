<?php

namespace App\Http\Controllers;

use App\Auth\MemberPhone;
use App\Models\Platform;
use App\Models\PlatformReview;
use App\Platforms\PlatformParticipation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformReviewController extends Controller
{
    public function index(int $platform): JsonResponse
    {
        $record = Platform::query()->available()->findOrFail($platform);
        $reviews = $record->reviews()->published()->with('member:id,phone')->latest('published_at')->latest('id')->paginate(20);

        return response()->json($reviews->through(fn (PlatformReview $review): array => [
            'id' => $review->id,
            'display_name' => $review->source === 'member'
                ? (MemberPhone::mask($review->member?->phone) ?? '会员')
                : Str::chopEnd($review->display_name, ' · AI 生成'),
            'rating' => $review->rating,
            'body' => $review->body, 'published_at' => $review->published_at, 'source' => $review->source,
        ]));
    }

    public function store(Request $request, int $platform, PlatformParticipation $participation): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:2000'],
        ]);
        $review = $participation->review($request->user(), $platform, $data);

        return response()->json(['data' => $this->submission($review)], $review->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'private, no-store');
    }

    public function mine(Request $request, int $platform): JsonResponse
    {
        $records = PlatformReview::query()->whereBelongsTo($request->user())
            ->where('platform_id', $platform)->latest('id')->paginate(20);

        return response()->json($records->through($this->submission(...)))->header('Cache-Control', 'private, no-store');
    }

    private function submission(PlatformReview $review): array
    {
        return [
            'id' => $review->id, 'rating' => $review->rating, 'body' => $review->body, 'status' => $review->status,
            'review_reason' => $review->review_reason, 'created_at' => $review->created_at,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Auth\MemberPhone;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Platforms\BindPlatformAccount;
use App\Platforms\PlatformParticipation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlatformController extends Controller
{
    private const PUBLIC_FIELDS = ['id', 'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => 'nullable|string|max:120']);
        $query = Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->orderBy('name')->orderBy('id');
        if (filled($filters['search'] ?? null)) {
            $query->whereLike('name', '%'.$filters['search'].'%');
        }

        return response()->json($query->paginate(20)->through($this->platformData(...)));
    }

    public function featured(): JsonResponse
    {
        $platforms = Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->whereNotNull('featured_position')
            ->orderBy('featured_position')->limit(10)->get();

        return response()->json(['data' => $platforms->map($this->platformData(...))]);
    }

    public function show(string $platform): JsonResponse
    {
        return response()->json([
            'data' => $this->platformData(Platform::query()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()->findOrFail($platform)),
        ]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $favorites = $request->user()->favoritePlatforms()->select(self::PUBLIC_FIELDS)->available()->withPublicReviewCount()
            ->orderByPivot('created_at', 'desc')->orderByDesc('platforms.id')->paginate(20);

        return response()->json($favorites->through($this->platformData(...)))->header('Cache-Control', 'private, no-store');
    }

    public function favoriteStatus(Request $request, int $platform): JsonResponse
    {
        $saved = $request->user()->favoritePlatforms()->available()->whereKey($platform)->exists();

        return response()->json(['data' => ['favorite' => $saved]])->header('Cache-Control', 'private, no-store');
    }

    public function favorite(Request $request, int $platform, PlatformParticipation $participation): Response
    {
        $participation->favorite($request->user(), $platform, true);

        return response()->noContent();
    }

    public function unfavorite(Request $request, int $platform, PlatformParticipation $participation): Response
    {
        $participation->favorite($request->user(), $platform, false);

        return response()->noContent();
    }

    public function bindings(Request $request): JsonResponse
    {
        $bindings = PlatformBinding::query()->where('member_id', $request->user()->id)
            ->with('platform:id,name')->orderByDesc('id')->paginate(20);

        return response()->json($bindings->through($this->bindingData(...)));
    }

    public function sendCode(Request $request, string $platform, BindPlatformAccount $binding): JsonResponse
    {
        $data = $this->validatePhone($request);
        $binding->sendCode($request->user(), Platform::query()->available()->findOrFail($platform), $data['phone']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function bind(Request $request, string $platform, BindPlatformAccount $binding): JsonResponse
    {
        $data = $this->validatePhone($request);
        $record = $binding->bind(
            $request->user(), Platform::query()->available()->findOrFail($platform), $data['phone'], $data['code'] ?? null,
        );

        return response()->json(['data' => $this->bindingData($record->load('platform:id,name'))], 201);
    }

    private function validatePhone(Request $request): array
    {
        $request->merge(['phone' => MemberPhone::normalize($request->input('phone'))]);

        return $request->validate([
            'phone' => ['required', MemberPhone::RULE],
            'code' => 'nullable|digits:6',
        ]);
    }

    private function platformData(Platform $platform): array
    {
        return [...$platform->only(self::PUBLIC_FIELDS), 'review_count' => (int) $platform->review_count,
            'member_review_count' => (int) $platform->member_review_count];
    }

    private function bindingData(PlatformBinding $binding): array
    {
        return [
            'id' => $binding->id,
            'platform_id' => $binding->platform_id,
            'platform_name' => $binding->platform->name,
            'platform_account_id' => $binding->platform_account_id,
            'created_at' => $binding->created_at?->toISOString(),
        ];
    }
}

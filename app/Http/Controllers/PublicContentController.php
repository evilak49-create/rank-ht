<?php

namespace App\Http\Controllers;

use App\Content\Publishing;
use App\Models\SiteArticle;
use App\Models\SiteBanner;
use Illuminate\Http\JsonResponse;

class PublicContentController extends Controller
{
    public function banners(Publishing $publishing): JsonResponse
    {
        $records = SiteBanner::query()->where('active', true)->orderBy('sort_order')->orderBy('id')->lazy(50)
            ->map(function (SiteBanner $banner) use ($publishing): ?array {
                $target = $publishing->bannerTarget($banner);

                return $target === null ? null : ['id' => $banner->id, 'name' => $banner->name, 'image_url' => $banner->image_url, 'target' => $target];
            })->filter()->take(5)->values()->all();

        return response()->json(['data' => $records]);
    }

    public function latest(): JsonResponse
    {
        $article = SiteArticle::query()->public()->where('type', 'announcement')->latest('published_at')->orderByDesc('id')->first();

        return response()->json(['data' => $article === null ? null : $this->summary($article)]);
    }

    public function announcements(): JsonResponse
    {
        $records = SiteArticle::query()->public()->where('type', 'announcement')->latest('published_at')->orderByDesc('id')->paginate(20);

        return response()->json($records->through($this->summary(...)));
    }

    public function help(): JsonResponse
    {
        $records = SiteArticle::query()->public()->where('type', 'help')->orderBy('sort_order')->orderBy('id')->paginate(20);

        return response()->json($records->through($this->summary(...)));
    }

    public function announcement(string $article, Publishing $publishing): JsonResponse
    {
        return $this->detail($article, 'announcement', $publishing);
    }

    public function helpArticle(string $article, Publishing $publishing): JsonResponse
    {
        return $this->detail($article, 'help', $publishing);
    }

    private function detail(string $id, string $type, Publishing $publishing): JsonResponse
    {
        $article = SiteArticle::query()->public()->where('type', $type)->findOrFail($id);

        return response()->json(['data' => [
            ...$this->summary($article), 'body' => $article->body, 'steps' => $article->steps ?? [],
            'body_html' => $article->body_html === null ? null : $publishing->sanitize($article->body_html),
            'action_target' => $publishing->publicTarget($article->target_type, $article->target_id),
        ]]);
    }

    private function summary(SiteArticle $article): array
    {
        return ['id' => $article->id, 'title' => $article->title, 'published_at' => $article->published_at?->toISOString()];
    }
}

<?php

namespace App\Http\Controllers;

use App\Storage\PublicImages;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteImageController extends Controller
{
    public function __invoke(string $filename): BinaryFileResponse
    {
        $disk = Storage::disk(PublicImages::LOCAL_DISK);
        abort_unless($disk->exists($filename), 404);
        $mime = array_search(pathinfo($filename, PATHINFO_EXTENSION), PublicImages::MIMES, true);

        return response()->file($disk->path($filename), [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

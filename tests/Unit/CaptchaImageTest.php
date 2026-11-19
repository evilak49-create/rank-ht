<?php

namespace Tests\Unit;

use App\Auth\CaptchaImage;
use GdImage;
use PHPUnit\Framework\TestCase;

class CaptchaImageTest extends TestCase
{
    public function test_adjacent_characters_do_not_share_strokes(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('GD is required to render the captcha.');
        }

        $renderer = new CaptchaImage;
        $code = $this->decode($renderer->render('K7RP'));
        $single = $this->decode($renderer->render('7'));

        // Read the second character of the full code and the first character of the single-character image.
        $this->assertSame($this->glyph($single, 15), $this->glyph($code, 45));
    }

    private function decode(string $data): GdImage
    {
        $image = imagecreatefromstring(base64_decode(explode(',', $data, 2)[1], true));
        $this->assertInstanceOf(GdImage::class, $image);

        return $image;
    }

    private function glyph(GdImage $image, int $left): array
    {
        $pixels = [];
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = $left; $x < $left + 30; $x++) {
                if (imagecolorat($image, $x, $y) === 0x0d342a) {
                    $pixels[] = [$x, $y];
                }
            }
        }
        $this->assertNotEmpty($pixels);
        $minX = min(array_column($pixels, 0));
        $minY = min(array_column($pixels, 1));

        return array_map(fn (array $pixel): array => [$pixel[0] - $minX, $pixel[1] - $minY], $pixels);
    }
}

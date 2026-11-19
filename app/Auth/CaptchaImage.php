<?php

namespace App\Auth;

class CaptchaImage
{
    public function render(string $answer): string
    {
        abort_unless(function_exists('imagepng'), 503, '图片验证码暂不可用。');
        $image = imagecreatetruecolor(150, 45);
        imagefill($image, 0, 0, imagecolorallocate($image, 239, 246, 242));
        $speckle = imagecolorallocate($image, 184, 210, 198);
        for ($i = 0; $i < 80; $i++) {
            imagesetpixel($image, random_int(0, 149), random_int(0, 44), $speckle);
        }
        $line = imagecolorallocate($image, 193, 218, 205);
        imageline($image, random_int(0, 20), random_int(0, 44), random_int(125, 149), random_int(0, 44), $line);

        $font = 5;
        $glyphWidth = imagefontwidth($font);
        $glyphHeight = imagefontheight($font);
        foreach (str_split($answer) as $index => $character) {
            $glyph = imagecreatetruecolor($glyphWidth, $glyphHeight);
            imagecolortransparent($glyph, imagecolorallocate($glyph, 0, 0, 0));
            $ink = imagecolorallocate($glyph, 13, 52, 42);
            imagechar($glyph, $font, 0, 0, $character, $ink);
            $x = 22 + $index * 30 + random_int(0, 5);
            $y = random_int(10, 14);
            imagecopyresized($image, $glyph, $x, $y, 0, 0, 12, 20, $glyphWidth, $glyphHeight);
            imagecopyresized($image, $glyph, $x + 1, $y, 0, 0, 12, 20, $glyphWidth, $glyphHeight);
        }

        ob_start();
        try {
            imagepng($image);

            return 'data:image/png;base64,'.base64_encode((string) ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }
}

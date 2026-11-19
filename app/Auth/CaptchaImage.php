<?php

namespace App\Auth;

class CaptchaImage
{
    public function render(string $answer): string
    {
        abort_unless(function_exists('imagepng'), 503, '图片验证码暂不可用。');
        $image = imagecreatetruecolor(150, 45);
        imagefill($image, 0, 0, imagecolorallocate($image, 239, 246, 242));
        for ($i = 0; $i < 220; $i++) {
            imagesetpixel($image, random_int(0, 149), random_int(0, 44), imagecolorallocate($image, 130, random_int(150, 205), 158));
        }
        $ink = imagecolorallocate($image, 13, 52, 42);
        foreach (str_split($answer) as $index => $character) {
            imagechar($image, 5, 14 + $index * 24 + random_int(0, 5), random_int(9, 23), $character, $ink);
        }
        $line = imagecolorallocate($image, 96, 143, 122);
        for ($i = 0; $i < 4; $i++) {
            imageline($image, random_int(0, 20), random_int(0, 44), random_int(125, 149), random_int(0, 44), $line);
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

<?php

namespace App\Support;

/**
 * 25-11-2026: photos in the inspection PDF all print at the same size. Each photo is fitted (not cropped, so the
 * stamp strip stays whole) into one fixed 4:3 canvas on a light background and embedded as a JPEG data URI.
 * DomPDF has no object-fit, so the shape has to be fixed in the image itself.
 */
class PdfPhoto
{
    public const WIDTH = 1200;

    public const HEIGHT = 900;

    public static function dataUri(string $bytes, string $mime = 'image/jpeg'): string
    {
        return 'data:'.(($fitted = self::fit($bytes)) !== null ? 'image/jpeg' : $mime).';base64,'.base64_encode($fitted ?? $bytes);
    }

    /** The photo fitted into the canvas as JPEG bytes, or null when GD is missing or the image cannot be read. */
    public static function fit(string $bytes): ?string
    {
        // getimagesizefromstring() refuses non-images quietly, so GD never sees bytes it cannot read
        if (! function_exists('imagecreatefromstring') || @getimagesizefromstring($bytes) === false) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(self::WIDTH / $w, self::HEIGHT / $h, 1.0);
        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 0xF1, 0xF5, 0xF9));
        imagecopyresampled($canvas, $src, intdiv(self::WIDTH - $dw, 2), intdiv(self::HEIGHT - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        ob_start();
        imagejpeg($canvas, null, 82);
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($canvas);

        return $out ?: null;
    }
}

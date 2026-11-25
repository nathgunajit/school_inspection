<?php

namespace Tests\Unit;

use App\Support\PdfPhoto;
use PHPUnit\Framework\TestCase;

/** 25-11-2026: every photo in the inspection PDF is fitted into the same 4:3 box. */
class PdfPhotoTest extends TestCase
{
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }

    public function test_portrait_and_landscape_photos_get_the_same_size(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not loaded');
        }
        foreach ([[300, 600], [800, 300], [2000, 1500]] as [$w, $h]) {
            $out = PdfPhoto::fit($this->png($w, $h));
            $this->assertNotNull($out);
            $this->assertSame([PdfPhoto::WIDTH, PdfPhoto::HEIGHT, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($out), 0, 3), "{$w}x{$h}");
        }
        $this->assertStringStartsWith('data:image/jpeg;base64,', PdfPhoto::dataUri($this->png(300, 600), 'image/png'));
    }

    public function test_unreadable_bytes_are_kept_as_they_are(): void
    {
        $this->assertNull(PdfPhoto::fit('not an image'));
        $this->assertSame('data:image/png;base64,'.base64_encode('not an image'), PdfPhoto::dataUri('not an image', 'image/png'));
    }
}

<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * 28-11-2026: QR codes as PNG data URIs (chillerlan/php-qrcode, drawn with GD), for DomPDF and web pages.
 * Used on the inspection PDF for the public, view-only page (InspectionVerifyController).
 */
class QrCode
{
    public static function dataUri(string $text, int $scale = 5): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);

        return (new Generator($options))->render($text);
    }
}

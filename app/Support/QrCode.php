<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * Server-side QR codes as PNG data URIs, sharp enough to print and usable in
 * both web pages and PDFs (no JavaScript needed).
 */
class QrCode
{
    public static function pngDataUri(string $data, int $scale = 8): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'quietzoneSize' => 2,
            'bgColor' => [255, 255, 255],
            'moduleValues' => [],
        ]);

        return (new Generator($options))->render($data);
    }
}

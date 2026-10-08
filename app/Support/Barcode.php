<?php

namespace App\Support;

/**
 * Code 128 (set B) barcodes drawn server-side as PNG data URIs, for web
 * pages and PDFs alike. Used for the booking reference on tickets.
 */
class Barcode
{
    /** Bar/space widths for symbol values 0–105, then the stop pattern. */
    private const Patterns = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    private const Stop = '2331112';

    private const StartB = 104;

    /**
     * Module widths (bar, space, bar...) for the text, quiet zones excluded.
     *
     * @return list<int>
     */
    public static function code128Widths(string $text): array
    {
        $values = [self::StartB];

        foreach (str_split($text) as $character) {
            $code = ord($character);

            if ($code < 32 || $code > 126) {
                throw new \InvalidArgumentException('Code 128 B only encodes printable ASCII.');
            }

            $values[] = $code - 32;
        }

        $checksum = self::StartB;

        foreach (array_slice($values, 1) as $position => $value) {
            $checksum += $value * ($position + 1);
        }

        $values[] = $checksum % 103;

        $pattern = implode('', array_map(fn (int $value): string => self::Patterns[$value], $values)).self::Stop;

        return array_map('intval', str_split($pattern));
    }

    public static function code128PngDataUri(string $text, int $height = 60, int $moduleWidth = 2): string
    {
        $widths = self::code128Widths($text);
        $quiet = 10 * $moduleWidth;
        $image = imagecreatetruecolor(array_sum($widths) * $moduleWidth + 2 * $quiet, $height);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 15, 27, 45);
        imagefill($image, 0, 0, $white);

        $x = $quiet;

        foreach ($widths as $index => $width) {
            if ($index % 2 === 0) {
                imagefilledrectangle($image, $x, 0, $x + $width * $moduleWidth - 1, $height - 1, $ink);
            }

            $x += $width * $moduleWidth;
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}

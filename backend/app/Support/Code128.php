<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Code 128 (set B) barcodes as SVG, for printed documents such as shipping labels. Set B covers the printable
 * ASCII characters that tracking IDs and order numbers use.
 */
final class Code128
{
    /** Bar and space widths of each symbol value 0–106 (106 is the stop pattern, which has a seventh element). */
    private const PATTERNS = [
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
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const STOP = 106;

    /** Quiet zone on each side, in modules. */
    private const QUIET_ZONE = 10;

    /**
     * The barcode as an SVG document, scaled to the given size.
     *
     * @throws InvalidArgumentException for characters outside printable ASCII
     */
    public static function svg(string $text, int $width = 300, int $height = 60): string
    {
        $runs = [];
        $position = self::QUIET_ZONE;

        foreach (self::symbols($text) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $index => $modules) {
                if ($index % 2 === 0) {
                    $runs[] = [$position, (int) $modules];
                }
                $position += (int) $modules;
            }
        }

        // Absolute coordinates (no viewBox scaling), so every renderer, PDF ones included, draws the same bars.
        $module = $width / ($position + self::QUIET_ZONE);
        $bars = implode('', array_map(
            fn (array $run) => sprintf('<rect x="%.3F" y="0" width="%.3F" height="%d"/>', $run[0] * $module, $run[1] * $module, $height),
            $runs,
        ));

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$width}\" height=\"{$height}\">"
            ."<rect width=\"{$width}\" height=\"{$height}\" fill=\"#fff\"/><g fill=\"#000\">{$bars}</g></svg>";
    }

    /** The barcode as a data URI, for an <img> in an HTML document. */
    public static function dataUri(string $text, int $width = 300, int $height = 60): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($text, $width, $height));
    }

    /**
     * Start code, data, checksum and stop code.
     *
     * @return list<int>
     */
    private static function symbols(string $text): array
    {
        $values = [self::START_B];

        foreach (str_split($text) as $character) {
            $code = ord($character);
            if ($code < 32 || $code > 126) {
                throw new InvalidArgumentException("Code 128 set B cannot encode \"{$character}\".");
            }
            $values[] = $code - 32;
        }

        $checksum = $values[0];
        foreach (array_slice($values, 1) as $position => $value) {
            $checksum += ($position + 1) * $value;
        }

        return [...$values, $checksum % 103, self::STOP];
    }
}

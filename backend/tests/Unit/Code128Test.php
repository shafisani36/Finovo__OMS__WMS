<?php

namespace Tests\Unit;

use App\Support\Code128;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Code 128 (set B) barcodes for shipping labels.
 */
class Code128Test extends TestCase
{
    public function test_a_barcode_has_the_bars_of_start_data_checksum_and_stop(): void
    {
        $svg = Code128::svg('A', 300, 60);

        // Start B (3 bars) + "A" (3) + checksum (3) + stop (4) = 13 bars, plus the white background.
        $this->assertSame(13, substr_count($svg, '<rect') - 1);
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="60">', $svg);
    }

    public function test_the_same_text_always_gives_the_same_barcode(): void
    {
        $this->assertSame(Code128::svg('TCS-77'), Code128::svg('TCS-77'));
        $this->assertNotSame(Code128::svg('TCS-77'), Code128::svg('TCS-78'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', Code128::dataUri('TCS-77'));
    }

    public function test_characters_outside_set_b_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Code128::svg("Bad\tcode");
    }
}

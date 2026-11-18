<?php

use Selective\ArchiveBomb\Engine\PngBompEngine;
use Selective\ArchiveBomb\Scanner\BombStatus;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Build a single PNG chunk.
 *
 * @param string $type The chunk type
 * @param string $data The chunk data
 *
 * @return string The encoded chunk
 */
function pngChunk(string $type, string $data): string
{
    $length = pack('N', strlen($data));
    $crc = pack('N', crc32($type . $data));

    return $length . $type . $data . $crc;
}

/**
 * Build a PNG file with the given IHDR fields and optional chunk stream.
 *
 * @param int $width The width
 * @param int $height The height
 * @param int $bitDepth The bit depth
 * @param int $colorType The colour type
 * @param int $interlace The interlace method
 * @param bool $withIend Whether to append the IEND terminator
 * @param bool $validIdatCrc Whether to emit a correct IDAT CRC
 * @param string $trailing Extra bytes appended after IEND
 *
 * @return string The PNG binary content
 */
function makePng(
    int $width,
    int $height,
    int $bitDepth = 8,
    int $colorType = 6,
    int $interlace = 0,
    bool $withIend = true,
    bool $validIdatCrc = true,
    string $trailing = ''
): string {
    $signature = "\x89PNG\x0d\x0a\x1a\x0a";
    $ihdrData = pack('NNCCCCC', $width, $height, $bitDepth, $colorType, 0, 0, $interlace);
    $idat = pngChunk('IDAT', zlib_encode('', ZLIB_ENCODING_RAW) ?: '');

    if (!$validIdatCrc) {
        // Corrupt the final CRC byte of the IDAT chunk.
        $idat = substr($idat, 0, -1) . chr(ord($idat[strlen($idat) - 1]) ^ 0xff);
    }

    $png = $signature . pngChunk('IHDR', $ihdrData) . $idat;

    if ($withIend) {
        $png .= pngChunk('IEND', '');
    }

    return $png . $trailing;
}

/**
 * Scan raw content through the PNG engine.
 *
 * @param string $content The content
 *
 * @return BombStatus The status
 */
function scanContent(string $content): BombStatus
{
    $filename = tempnam(sys_get_temp_dir(), 'png-matrix-');

    if ($filename === false) {
        throw new RuntimeException('Unable to create temporary file.');
    }

    try {
        file_put_contents($filename, $content);
        $engine = new PngBompEngine();

        return $engine->scanFile(new SplFileObject($filename))->getStatus();
    } finally {
        unlink($filename);
    }
}

/**
 * Scan a fixture file through the PNG engine.
 *
 * @param string $filename The fixture path
 *
 * @return BombStatus The status
 */
function scanFixture(string $filename): BombStatus
{
    $engine = new PngBompEngine();

    return $engine->scanFile(new SplFileObject($filename))->getStatus();
}

// 400,000,000 decoded bytes budget (10000 * 10000 * 4 channels * 1 byte).
$matrix = [
    // Existing fixtures.
    ['fixture png-ok/ok.png (32x32 RGBA8)', scanFixture(__DIR__ . '/files/png-ok/ok.png'), BombStatus::Safe],
    ['fixture png/bomb.png (225000x225000 indexed)', scanFixture(__DIR__ . '/files/png/bomb.png'), BombStatus::Bomb],

    // Legal desktop sized images must keep passing.
    ['desktop 1920x1080 RGBA8', scanContent(makePng(1920, 1080)), BombStatus::Safe],
    ['desktop 3840x2160 RGB8', scanContent(makePng(3840, 2160, 8, 2)), BombStatus::Safe],
    ['desktop 7680x4320 RGBA8', scanContent(makePng(7680, 4320)), BombStatus::Safe],
    ['desktop 10000x10000 RGB8 (300MB)', scanContent(makePng(10000, 10000, 8, 2)), BombStatus::Safe],
    ['desktop 10000x10000 RGBA8 (400MB, at budget)', scanContent(makePng(10000, 10000)), BombStatus::Safe],
    ['desktop 5000x5000 RGBA16 (400MB, at budget)', scanContent(makePng(5000, 5000, 16, 6)), BombStatus::Safe],
    ['desktop 10000x10000 gray16 (200MB)', scanContent(makePng(10000, 10000, 16, 0)), BombStatus::Safe],
    ['desktop 800x600 indexed 1-bit', scanContent(makePng(800, 600, 1, 3)), BombStatus::Safe],
    ['desktop 800x600 gray 2-bit Adam7', scanContent(makePng(800, 600, 2, 0, 1)), BombStatus::Safe],

    // Pixel budget boundaries expressed in decoded bytes.
    ['boundary 10001x10000 RGBA8 (over budget)', scanContent(makePng(10001, 10000)), BombStatus::Bomb],
    ['boundary 10000x10000 RGBA16 (over budget)', scanContent(makePng(10000, 10000, 16, 6)), BombStatus::Bomb],
    ['boundary 1x400000000 gray8 (at budget)', scanContent(makePng(1, 400000000, 8, 0)), BombStatus::Safe],
    ['boundary 1x400000001 gray8 (over budget)', scanContent(makePng(1, 400000001, 8, 0)), BombStatus::Bomb],

    // Extremely narrow but very long images.
    ['narrow-long 1x10000000 RGB8 (30MB)', scanContent(makePng(1, 10000000, 8, 2)), BombStatus::Safe],
    ['narrow-long 100x4000000 RGBA8 (1.6GB)', scanContent(makePng(100, 4000000)), BombStatus::Bomb],
    [
        'narrow-long 1x2147483647 gray16 (valid max IHDR, over budget)',
        scanContent(makePng(1, 2147483647, 16, 0)),
        BombStatus::Bomb,
    ],

    // Malformed IHDR: invalid value ranges and unsupported combinations.
    ['malformed IHDR zero width', scanContent(makePng(0, 100)), BombStatus::Unknown],
    ['malformed IHDR zero height', scanContent(makePng(100, 0)), BombStatus::Unknown],
    ['malformed IHDR bit depth 3', scanContent(makePng(100, 100, 3, 0)), BombStatus::Unknown],
    ['malformed IHDR bit depth 12 RGB', scanContent(makePng(100, 100, 12, 2)), BombStatus::Unknown],
    ['malformed IHDR indexed bit depth 16', scanContent(makePng(100, 100, 16, 3)), BombStatus::Unknown],
    ['malformed IHDR color type 5', scanContent(makePng(100, 100, 8, 5)), BombStatus::Unknown],
    ['malformed IHDR color type 7', scanContent(makePng(100, 100, 8, 7)), BombStatus::Unknown],
    ['malformed IHDR compression method 1', scanContent(makeRawIhdrPng(100, 100, 8, 6, 1, 0, 0)), BombStatus::Unknown],
    ['malformed IHDR filter method 1', scanContent(makeRawIhdrPng(100, 100, 8, 6, 0, 1, 0)), BombStatus::Unknown],
    ['malformed IHDR interlace method 2', scanContent(makeRawIhdrPng(100, 100, 8, 6, 0, 0, 2)), BombStatus::Unknown],

    // Truncated streams and corrupt chunks.
    ['truncated signature only', scanContent("\x89PNG\x0d\x0a\x1a\x0a"), BombStatus::Unknown],
    ['truncated IHDR missing data', scanContent(substr(makePng(100, 100), 0, 20)), BombStatus::Unknown],
    ['truncated before IEND', scanContent(makePng(100, 100, 8, 6, 0, false)), BombStatus::Unknown],
    ['corrupt IDAT CRC', scanContent(makePng(100, 100, 8, 6, 0, true, false)), BombStatus::Unknown],
    ['trailing bytes after IEND', scanContent(makePng(100, 100, 8, 6, 0, true, true, 'x')), BombStatus::Unknown],

    // Unsupported / foreign formats are not PNGs and stay delegated to others.
    ['non-PNG plain text', scanContent('not a png at all'), BombStatus::Safe],
    ['non-PNG ZIP local header', scanContent("PK\x03\x04rest"), BombStatus::Safe],
];

/**
 * Build a PNG whose IHDR carries raw method fields for invalid-value tests.
 *
 * @param int $width The width
 * @param int $height The height
 * @param int $bitDepth The bit depth
 * @param int $colorType The colour type
 * @param int $compression The compression method
 * @param int $filter The filter method
 * @param int $interlace The interlace method
 *
 * @return string The PNG binary content
 */
function makeRawIhdrPng(
    int $width,
    int $height,
    int $bitDepth,
    int $colorType,
    int $compression,
    int $filter,
    int $interlace
): string {
    $signature = "\x89PNG\x0d\x0a\x1a\x0a";
    $ihdrData = pack('NNCCCCC', $width, $height, $bitDepth, $colorType, $compression, $filter, $interlace);

    return $signature . pngChunk('IHDR', $ihdrData) . pngChunk('IEND', '');
}

$failures = 0;

printf("%-48s %-10s %-10s %s\n", 'Case', 'Expected', 'Actual', 'Result');
echo str_repeat('-', 84) . PHP_EOL;

foreach ($matrix as [$label, $actual, $expected]) {
    $ok = $actual === $expected;
    $failures += $ok ? 0 : 1;

    printf(
        "%-48s %-10s %-10s %s\n",
        $label,
        $expected->value,
        $actual->value,
        $ok ? 'PASS' : 'FAIL'
    );
}

echo str_repeat('-', 84) . PHP_EOL;
printf('%d cases, %d failure(s)%s', count($matrix), $failures, PHP_EOL);

exit($failures === 0 ? 0 : 1);

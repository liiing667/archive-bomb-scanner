<?php

namespace Selective\ArchiveBomb\Engine;

use RuntimeException;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Scanner\BombStatus;
use SplFileObject;

/**
 * PNG bomb engine.
 */
final class PngBompEngine implements EngineInterface
{
    /**
     * PNG signature.
     */
    private const PNG_SIGNATURE = "\x89PNG\x0d\x0a\x1a\x0a";

    /**
     * Maximum decoded image size in bytes (width * height * channels * bytes per sample).
     *
     * 10000 * 10000 pixels with 4 channels and 1 byte per sample, i.e. the
     * historical 10000x10000 pixel threshold expressed as a decoded byte budget.
     */
    private const MAX_DECODED_BYTES = 400000000;

    /**
     * Maximum chunk length that is buffered in memory, 16 MiB.
     */
    private const MAX_BUFFERED_CHUNK_LENGTH = 16777216;

    /**
     * Absolute chunk length guard against corrupted length fields, 1 GiB.
     */
    private const MAX_CHUNK_LENGTH = 1073741824;

    /**
     * Number of samples per pixel for each valid PNG colour type.
     *
     * @var array<int, int>
     */
    private const CHANNELS_BY_COLOR_TYPE = [
        0 => 1, // Grayscale
        2 => 3, // RGB
        3 => 1, // Indexed
        4 => 2, // Grayscale + alpha
        6 => 4, // RGBA
    ];

    /**
     * Allowed bit depths per PNG colour type.
     *
     * @var array<int, array<int, int>>
     */
    private const BIT_DEPTHS_BY_COLOR_TYPE = [
        0 => [1, 2, 4, 8, 16],
        2 => [8, 16],
        3 => [1, 2, 4, 8],
        4 => [8, 16],
        6 => [8, 16],
    ];

    /**
     * Scan for PNG bomb.
     *
     * @param SplFileObject $file The png file
     *
     * @throws RuntimeException
     *
     * @return BombScannerResult The result
     */
    public function scanFile(SplFileObject $file): BombScannerResult
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException(sprintf('File not found: %s', $file->getFilename()));
        }

        if (!$this->isPng($file)) {
            // This is not a PNG, other engines are responsible for it.
            return new BombScannerResult(BombStatus::Safe);
        }

        $header = $this->readIhdr($file);

        if ($header === null) {
            // Truncated or malformed header, the safety cannot be established.
            return new BombScannerResult(BombStatus::Unknown);
        }

        if (!$this->isSupportedIhdr($header)) {
            // Unsupported or invalid bit depth, colour type or method.
            return new BombScannerResult(BombStatus::Unknown);
        }

        if (!$this->hasValidChunks($file)) {
            // Truncated chunk stream, bad CRC or a missing IEND terminator.
            return new BombScannerResult(BombStatus::Unknown);
        }

        $channels = self::CHANNELS_BY_COLOR_TYPE[$header['colorType']];
        $bytesPerSample = (int)ceil($header['bitDepth'] / 8);

        // Decoded upper bound of the raw pixel data. Sub-byte bit depths
        // share one sample byte, so each sample consumes ceil(bitDepth / 8)
        // bytes; the per-scanline filter byte and Adam7 overhead stay below
        // 0.00001% of the budget at the bomb boundary and are not counted.
        $bytesPerPixel = $channels * $bytesPerSample;
        // Integer multiplication keeps the exact budget boundary; a result
        // beyond PHP_INT_MAX overflows into a float that is still way above
        // the byte budget.
        $decodedBytes = $header['width'] * $header['height'] * $bytesPerPixel;

        if ($decodedBytes > self::MAX_DECODED_BYTES) {
            return new BombScannerResult(BombStatus::Bomb);
        }

        return new BombScannerResult(BombStatus::Safe);
    }

    /**
     * Detect file type.
     *
     * @param SplFileObject $file The file
     *
     * @return bool The status
     */
    private function isPng(SplFileObject $file): bool
    {
        $file->rewind();

        return $file->fread(8) === self::PNG_SIGNATURE;
    }

    /**
     * Read and parse the IHDR chunk.
     *
     * @param SplFileObject $file The png file
     *
     * @return array<string, int>|null The parsed header or null on truncation
     */
    private function readIhdr(SplFileObject $file): ?array
    {
        $file->rewind();
        $file->fread(8);

        $lengthBytes = $file->fread(4);
        $type = $file->fread(4);

        if (strlen((string)$lengthBytes) < 4 || $type !== 'IHDR') {
            return null;
        }

        $length = unpack('N', (string)$lengthBytes)[1];

        if ($length !== 13) {
            return null;
        }

        $data = $file->fread(13);

        if (strlen((string)$data) < 13) {
            return null;
        }

        $values = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', (string)$data);

        if ($values === false) {
            return null;
        }

        return [
            'width' => (int)$values['width'],
            'height' => (int)$values['height'],
            'bitDepth' => (int)$values['bitDepth'],
            'colorType' => (int)$values['colorType'],
            'compressionMethod' => (int)$values['compression'],
            'filterMethod' => (int)$values['filter'],
            'interlaceMethod' => (int)$values['interlace'],
        ];
    }

    /**
     * Validate the value ranges and constraints of the IHDR fields.
     *
     * @param array<string, int> $header The parsed header
     *
     * @return bool The status
     */
    private function isSupportedIhdr(array $header): bool
    {
        // PNG forbids zero dimensions and limits each side to 2^31 - 1.
        if ($header['width'] < 1 || $header['height'] < 1) {
            return false;
        }

        if ($header['width'] > 2147483647 || $header['height'] > 2147483647) {
            return false;
        }

        if (!isset(self::BIT_DEPTHS_BY_COLOR_TYPE[$header['colorType']])) {
            return false;
        }

        if (!in_array($header['bitDepth'], self::BIT_DEPTHS_BY_COLOR_TYPE[$header['colorType']], true)) {
            return false;
        }

        // Only deflate compression and the standard filter set are defined.
        if ($header['compressionMethod'] !== 0 || $header['filterMethod'] !== 0) {
            return false;
        }

        // 0 = no interlace, 1 = Adam7; every other value is invalid.
        if (!in_array($header['interlaceMethod'], [0, 1], true)) {
            return false;
        }

        return true;
    }

    /**
     * Walk every chunk and validate structure, CRC and the IEND terminator.
     *
     * @param SplFileObject $file The png file
     *
     * @return bool The status
     */
    private function hasValidChunks(SplFileObject $file): bool
    {
        $fileSize = $file->getSize();

        if ($fileSize === false || $fileSize < 33) {
            return false;
        }

        $offset = 8;
        $sawIend = false;

        while ($offset + 12 <= $fileSize) {
            $file->fseek($offset);
            $header = $file->fread(8);

            if (strlen((string)$header) < 8) {
                return false;
            }

            $length = unpack('N', substr((string)$header, 0, 4))[1];

            if ($length > self::MAX_CHUNK_LENGTH || $offset + 12 + $length > $fileSize) {
                return false;
            }

            if (!$this->isValidChunkCrc($file, $offset, (int)$length)) {
                return false;
            }

            $type = substr((string)$header, 4, 4);
            $offset += 12 + (int)$length;

            if ($type === 'IEND') {
                $sawIend = true;

                // IEND must terminate the stream; trailing data is invalid.
                break;
            }
        }

        return $sawIend && $offset === $fileSize;
    }

    /**
     * Verify the CRC of a single chunk without buffering large payloads.
     *
     * @param SplFileObject $file The png file
     * @param int $offset The chunk offset (length field)
     * @param int $length The chunk data length
     *
     * @return bool The status
     */
    private function isValidChunkCrc(SplFileObject $file, int $offset, int $length): bool
    {
        $file->fseek($offset + 4);
        $context = hash_init('crc32b');
        $remaining = $length + 4;

        while ($remaining > 0) {
            $chunk = (string)$file->fread(min(self::MAX_BUFFERED_CHUNK_LENGTH, $remaining));

            if ($chunk === '') {
                return false;
            }

            hash_update($context, $chunk);
            $remaining -= strlen($chunk);
        }

        $crcBytes = $file->fread(4);

        if (strlen((string)$crcBytes) < 4) {
            return false;
        }

        $actualCrc = unpack('N', (string)$crcBytes)[1];
        $expectedCrc = hexdec(hash_final($context));

        return $actualCrc === $expectedCrc;
    }
}

<?php

namespace Selective\ArchiveBomb\Test;

use PHPUnit\Framework\TestCase;
use Selective\ArchiveBomb\Engine\ZipBombEngine;
use Selective\ArchiveBomb\Scanner\BombScanner;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use SplFileObject;

/**
 * Offline matrix of corrupted and manipulated ZIP archives.
 *
 * Every fixture is built in memory from raw ZIP records, so the matrix does
 * not depend on any external tools or network access. The declared central
 * directory sizes are attacker controlled and must only be used as budget
 * hints; the actual data stream decides between safe, bomb and unknown.
 */
final class CorruptedZipTest extends TestCase
{
    private const NAME = 'member.bin';

    /**
     * The matrix.
     *
     * @dataProvider corruptedArchiveMatrix
     *
     * @param string $contents The archive contents
     * @param string $expectedStatus The expected result status
     * @param bool $expectedBomb The expected bomb flag
     */
    public function testCorruptedArchiveMatrix(string $contents, string $expectedStatus, bool $expectedBomb): void
    {
        $path = tempnam(sys_get_temp_dir(), 'abs-corrupt-');
        $this->assertIsString($path);

        try {
            file_put_contents($path, $contents);

            $scanner = new BombScanner();
            $scanner->addEngine(new ZipBombEngine(100));

            $result = $scanner->scanFile(new SplFileObject($path));

            $this->assertSame($expectedStatus, $result->getStatus());
            $this->assertSame($expectedBomb, $result->isBomb());
            $this->assertSame($expectedStatus === BombScannerResult::STATUS_SAFE, $result->isSafe());
            $this->assertSame($expectedStatus === BombScannerResult::STATUS_UNKNOWN, $result->isUnknown());
            $this->assertSame('zip', $result->getEngineName());
            $this->assertNotSame('', $result->getReason());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Provide the corrupted archive matrix.
     *
     * @return array<string, array<int, mixed>> The matrix
     */
    public static function corruptedArchiveMatrix(): array
    {
        $data = str_repeat('archive bomb scanner payload ', 100);

        return [
            'clean deflate archive is safe' => [
                self::buildZip([self::entry(self::NAME, $data, 8)]),
                BombScannerResult::STATUS_SAFE,
                false,
            ],
            'clean stored archive is safe' => [
                self::buildZip([self::entry(self::NAME, $data, 0)]),
                BombScannerResult::STATUS_SAFE,
                false,
            ],
            'declared original size larger than stream is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['original_size' => strlen($data) + 1000])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'declared original size smaller than stream is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['original_size' => 10])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'declared compressed size larger than stream is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['compressed_size' => 100000])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'declared compressed size smaller than stream is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['compressed_size' => 4])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'corrupted crc is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['crc' => 0xdeadbeef])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'method conflict between central directory and local header is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['central_method' => 0])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'unsupported compression method is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['central_method' => 12, 'local_method' => 12])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'encrypted entry is unknown' => [
                self::buildZip([self::entry(self::NAME, $data, 8, ['flags' => 1])]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'truncated deflate stream is unknown' => [
                self::buildTruncatedArchive($data),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'truncated archive without central directory is unknown' => [
                "PK\3\4" . random_bytes(64),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'real deflate bomb is detected from the stream' => [
                self::buildZip([self::entry('zeros.bin', str_repeat("\0", 2000000), 8)]),
                BombScannerResult::STATUS_BOMB,
                true,
            ],
            'declared bomb with honest small stream is not a bomb' => [
                self::buildZip([
                    self::entry(self::NAME, $data, 0, ['original_size' => 100000000]),
                ]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'nested archive with a real bomb is detected' => [
                self::buildZip([
                    self::entry(
                        'inner.zip',
                        self::buildZip([self::entry('zeros.bin', str_repeat("\0", 2000000), 8)]),
                        8
                    ),
                ]),
                BombScannerResult::STATUS_BOMB,
                true,
            ],
            'nested archive with spoofed declarations is unknown' => [
                self::buildZip([
                    self::entry(
                        'inner.zip',
                        self::buildZip([
                            self::entry(self::NAME, $data, 0, ['original_size' => 100000000]),
                        ]),
                        8
                    ),
                ]),
                BombScannerResult::STATUS_UNKNOWN,
                false,
            ],
            'multiple clean entries are safe' => [
                self::buildZip([
                    self::entry('one.txt', $data, 8),
                    self::entry('two.txt', $data, 0),
                    self::entry('three.txt', $data, 8),
                ]),
                BombScannerResult::STATUS_SAFE,
                false,
            ],
        ];
    }

    /**
     * Build a raw local and central directory entry.
     *
     * @param string $name The entry name
     * @param string $data The uncompressed data
     * @param int $method The compression method
     * @param array<string, int> $overrides The field overrides
     *
     * @return array<string, mixed> The entry
     */
    private static function entry(string $name, string $data, int $method, array $overrides = []): array
    {
        $payload = $method === 8 ? (string)gzdeflate($data) : $data;

        return [
            'name' => $name,
            'data' => $data,
            'payload' => $payload,
            'method' => $method,
            'flags' => (int)($overrides['flags'] ?? 0),
            'crc' => (int)($overrides['crc'] ?? crc32($data)),
            'compressed_size' => (int)($overrides['compressed_size'] ?? strlen($payload)),
            'original_size' => (int)($overrides['original_size'] ?? strlen($data)),
            'central_method' => (int)($overrides['central_method'] ?? $method),
            'local_method' => (int)($overrides['local_method'] ?? $method),
        ];
    }

    /**
     * Build a complete ZIP archive from raw entries.
     *
     * @param array<int, array<string, mixed>> $entries The entries
     *
     * @return string The archive contents
     */
    private static function buildZip(array $entries): string
    {
        $local = '';
        $central = '';

        foreach ($entries as $entry) {
            $name = (string)$entry['name'];
            $offset = strlen($local);

            $local .= "PK\3\4" . pack(
                'vvvvvVVVvv',
                20,
                (int)$entry['flags'],
                (int)$entry['local_method'],
                0,
                0,
                (int)$entry['crc'],
                (int)$entry['compressed_size'],
                (int)$entry['original_size'],
                strlen($name),
                0
            ) . $name . (string)$entry['payload'];

            $central .= "PK\1\2" . pack(
                'vvvvvvVVVvvvvvVV',
                20,
                20,
                (int)$entry['flags'],
                (int)$entry['central_method'],
                0,
                0,
                (int)$entry['crc'],
                (int)$entry['compressed_size'],
                (int)$entry['original_size'],
                strlen($name),
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;
        }

        $archive = $local . $central;

        $archive .= "PK\5\6" . pack(
            'vvvvVVv',
            0,
            0,
            count($entries),
            count($entries),
            strlen($central),
            strlen($local),
            0
        );

        return $archive;
    }

    /**
     * Build an archive whose payload is cut off before the stream completes.
     *
     * @param string $data The uncompressed data
     *
     * @return string The truncated archive
     */
    private static function buildTruncatedArchive(string $data): string
    {
        $entry = self::entry(self::NAME, $data, 8);
        $name = (string)$entry['name'];
        $payload = (string)$entry['payload'];

        // Cut the final byte off the actual deflate payload, then rebuild the
        // central directory and EOCD around the damaged local record so the
        // truncation is only visible while reading the data stream.
        $damagedPayload = substr($payload, 0, -1);
        $localHeader = "PK\3\4" . pack(
            'vvvvvVVVvv',
            20,
            0,
            8,
            0,
            0,
            (int)$entry['crc'],
            strlen($damagedPayload),
            (int)$entry['original_size'],
            strlen($name),
            0
        );

        $local = $localHeader . $name . $damagedPayload;

        $central = "PK\1\2" . pack(
            'vvvvvvVVVvvvvvVV',
            20,
            20,
            0,
            8,
            0,
            0,
            (int)$entry['crc'],
            strlen($damagedPayload),
            (int)$entry['original_size'],
            strlen($name),
            0,
            0,
            0,
            0,
            0,
            0
        ) . $name;

        $archive = $local . $central;

        $archive .= "PK\5\6" . pack(
            'vvvvVVv',
            0,
            0,
            1,
            1,
            strlen($central),
            strlen($local),
            0
        );

        return $archive;
    }
}

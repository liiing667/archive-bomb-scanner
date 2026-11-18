<?php

namespace Selective\ArchiveBomb\Engine;

use RuntimeException;
use Selective\ArchiveBomb\Scanner\BombEvidence;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Scanner\BombStatus;
use SplFileObject;

/**
 * PNG bomb engine.
 */
final class PngBompEngine implements EngineInterface
{
    /**
     * Stable identifier used as prefix for every evidence identity.
     */
    public const ENGINE_ID = 'png';

    /**
     * Get the stable engine identifier.
     *
     * @return string The engine identifier
     */
    public function getId(): string
    {
        return self::ENGINE_ID;
    }

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
            // This is not a PNG
            return new BombScannerResult(BombStatus::Safe);
        }

        $file->rewind();
        $file->fread(8 + 4);
        $idr = $file->fread(4);

        // Make sure we have an IHDR
        if ($idr !== 'IHDR') {
            throw new RuntimeException('No PNG IHDR header found, invalid PNG file.');
        }

        // PNG actually stores Width and height integers in big-endian.
        $width = unpack('N', (string)$file->fread(4))[1];
        $height = unpack('N', (string)$file->fread(4))[1];

        if ($width > 10000 || $height > 10000) {
            // Invalid image
            return new BombScannerResult(
                BombStatus::Bomb,
                [
                    new BombEvidence(
                        $this->getId(),
                        'image#dimensions',
                        BombStatus::Bomb,
                        sprintf('PNG dimensions %dx%d exceed the 10000 pixel limit.', $width, $height),
                        0.0,
                        (float)($width * $height)
                    ),
                ]
            );
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

        return $file->fread(4) === chr(0x89) . 'PNG';
    }
}

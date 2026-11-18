<?php

namespace Selective\ArchiveBomb\Engine;

use RuntimeException;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\ScanStatus;
use SplFileObject;

/**
 * PNG bomb engine.
 */
final class PngBompEngine implements EngineInterface
{
    /**
     * Scan for PNG bomb.
     *
     * @param SplFileObject $file The png file
     * @param string $containerId The id of the uploaded container
     *
     * @throws RuntimeException
     *
     * @return BombScannerResult The result
     */
    public function scanFile(SplFileObject $file, string $containerId = ''): BombScannerResult
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException(sprintf('File not found: %s', $file->getFilename()));
        }

        if (!$this->isPng($file)) {
            // This is not a PNG
            return new BombScannerResult(false, $containerId);
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
            return new BombScannerResult(true, $containerId, ArchiveType::unknown(), ScanStatus::RegularFile);
        }

        return new BombScannerResult(false, $containerId, ArchiveType::unknown(), ScanStatus::RegularFile);
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

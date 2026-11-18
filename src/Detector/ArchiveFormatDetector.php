<?php

namespace Selective\ArchiveBomb\Detector;

use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\FormatDetectionResult;
use SplFileObject;

/**
 * Detects archive formats by their content magic numbers, so renamed or
 * extension-less members are still recognised correctly.
 */
final class ArchiveFormatDetector
{
    /**
     * The maximum number of bytes scanned when searching the ZIP end of
     * central directory record.
     */
    private const ZIP_EOCD_SCAN_LIMIT = 65557;

    /**
     * Detect the format of in-memory contents.
     *
     * @param string $contents The contents
     *
     * @return FormatDetectionResult The detection result
     */
    public function detectString(string $contents): FormatDetectionResult
    {
        $type = $this->detectTypeBySignature($contents);

        if ($type->isUnknown()) {
            return new FormatDetectionResult($type);
        }

        $truncated = $type->getId() === ArchiveType::ZIP && !$this->hasZipEndOfCentralDirectory($contents);

        return new FormatDetectionResult($type, $truncated);
    }

    /**
     * Detect the format of a file.
     *
     * @param SplFileObject $file The file
     *
     * @return FormatDetectionResult The detection result
     */
    public function detectFile(SplFileObject $file): FormatDetectionResult
    {
        $file->rewind();
        $head = (string)$file->fread(262);
        $type = $this->detectTypeBySignature($head);

        if ($type->isUnknown()) {
            return new FormatDetectionResult($type);
        }

        if ($type->getId() !== ArchiveType::ZIP) {
            return new FormatDetectionResult($type);
        }

        $fileSize = $file->getSize();

        if ($fileSize === false || $fileSize < 22) {
            return new FormatDetectionResult($type, true);
        }

        $tailSize = min($fileSize, self::ZIP_EOCD_SCAN_LIMIT);
        $file->fseek($fileSize - $tailSize);
        $tail = (string)$file->fread($tailSize);

        return new FormatDetectionResult($type, !$this->hasZipEndOfCentralDirectory($tail));
    }

    /**
     * Match the content against known archive signatures.
     *
     * @param string $contents The contents
     *
     * @return ArchiveType The matched type or an unknown type
     */
    private function detectTypeBySignature(string $contents): ArchiveType
    {
        if ($contents === '') {
            return ArchiveType::unknown();
        }

        // ZIP local file header, end of central directory or spanned marker.
        if (
            str_starts_with($contents, "PK\3\4") || str_starts_with($contents, "PK\5\6")
            || str_starts_with($contents, "PK\7\8")
        ) {
            return ArchiveType::zip();
        }

        // RAR 4.x and 5.x both start with "Rar!\x1a\x07", followed by 0 or 1.
        if (str_starts_with($contents, "\x52\x61\x72\x21\x1a\x07")) {
            return ArchiveType::rar();
        }

        // 7-Zip signature: 37 7A BC AF 27 1C.
        if (str_starts_with($contents, "7z\xbc\xaf\x27\x1c")) {
            return ArchiveType::sevenZip();
        }

        // XZ signature: FD 37 7A 58 5A 00.
        if (str_starts_with($contents, "\xfd7zXZ\0")) {
            return ArchiveType::xz();
        }

        // GZIP signature: 1F 8B 08.
        if (str_starts_with($contents, "\x1f\x8b\x08")) {
            return ArchiveType::gzip();
        }

        // BZIP2 signature: "BZh" followed by the block size digit 1-9.
        if (preg_match('/^BZh[1-9]/', $contents) === 1) {
            return ArchiveType::bzip2();
        }

        return ArchiveType::unknown();
    }

    /**
     * Check whether the given bytes contain a ZIP end of central directory record.
     *
     * @param string $contents The contents
     *
     * @return bool The status
     */
    private function hasZipEndOfCentralDirectory(string $contents): bool
    {
        $length = strlen($contents);

        if ($length < 22) {
            return false;
        }

        $start = max(0, $length - self::ZIP_EOCD_SCAN_LIMIT);

        for ($offset = $length - 22; $offset >= $start; $offset--) {
            if (substr($contents, $offset, 4) === "PK\5\6") {
                return true;
            }
        }

        return false;
    }
}

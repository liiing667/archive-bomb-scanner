<?php

namespace Selective\ArchiveBomb\Engine;

use RuntimeException;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use SplFileObject;

/**
 * ZIP bomb scanner.
 *
 * The sizes declared in the ZIP central directory are attacker controlled and
 * are therefore only used as budget hints. Every member is read from its local
 * file header and verified against the actual data stream. Structural problems,
 * truncated streams, CRC failures or method conflicts make the result
 * inconclusive ("unknown") instead of silently reporting the archive as safe.
 */
final class ZipBombEngine implements EngineInterface
{
    private const ENGINE_NAME = 'zip';
    private const MAX_RECURSION_DEPTH = 20;
    private const MAX_NESTED_ARCHIVE_SIZE = 104857600;
    private const MAX_ENTRY_DECOMPRESSED_SIZE = 104857600;

    /**
     * @var bool Whether entries that could not be fully verified were found
     */
    private bool $unknownEntries = false;

    /**
     * @var int
     */
    private int $maxRatio;

    /**
     * The constructor.
     *
     * @param int $maxRatio The max Bomb size ratio (Original size / Compressed size)
     */
    public function __construct(int $maxRatio = 1000)
    {
        $this->maxRatio = $maxRatio;
    }

    /**
     * Scan for ZIP bomb.
     *
     * @param SplFileObject $file The zip file
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

        if (!$this->isZip($file)) {
            return new BombScannerResult(false, self::ENGINE_NAME, 'Not a ZIP file');
        }

        // The verification state is only valid within a single scan.
        $this->unknownEntries = false;

        $fileSize = (int)$file->getSize();

        if ($fileSize <= 0) {
            return new BombScannerResult(false, self::ENGINE_NAME, 'Empty file');
        }

        $maxSize = $fileSize * $this->maxRatio;
        $accumulator = new SizeAccumulator();
        $isBomb = $this->scanSource(new FileZipSource($file), $maxSize, 0, $accumulator);

        if ($isBomb) {
            return new BombScannerResult(
                true,
                self::ENGINE_NAME,
                sprintf('ZIP bomb detected: expansion ratio exceeds %d', $this->maxRatio),
                BombScannerResult::STATUS_BOMB
            );
        }

        if ($this->unknownEntries) {
            return new BombScannerResult(
                false,
                self::ENGINE_NAME,
                'Archive contains encrypted, unsupported or corrupted entries',
                BombScannerResult::STATUS_UNKNOWN
            );
        }

        return new BombScannerResult(
            false,
            self::ENGINE_NAME,
            'No ZIP bomb detected',
            BombScannerResult::STATUS_SAFE
        );
    }

    /**
     * Scan every member of a ZIP source and accumulate its measured expansion.
     *
     * The same accumulator is shared across all recursion levels so deep nesting
     * short circuits against the global budget instead of exploding into a full
     * tree traversal.
     *
     * @param ZipSource $source The archive source
     * @param float $maxSize The global size budget
     * @param int $depth The recursion depth
     * @param SizeAccumulator $accumulator The shared running total
     *
     * @return bool Whether bomb evidence was found
     *
     * @phpstan-impure
     */
    private function scanSource(
        ZipSource $source,
        float $maxSize,
        int $depth,
        SizeAccumulator $accumulator
    ): bool {
        $entries = $source->readCentralDirectoryEntries();

        if ($entries === null) {
            // The container claims to be a ZIP but its structure is broken.
            $this->unknownEntries = true;

            return false;
        }

        foreach ($entries as $entry) {
            $declaredCompressedSize = (int)$entry['compressed_size'];
            $declaredOriginalSize = (int)$entry['original_size'];

            $verified = $this->verifyEntry($source, $entry, $depth, $maxSize, $accumulator);

            if ($verified->isBomb) {
                return true;
            }

            $entryOriginalSize = $verified->originalSize;
            $entryCompressedSize = $verified->compressedSize;

            // Ratio evidence is only trusted for members whose actual stream was
            // fully verified. Corrupted or encrypted members already downgrade the
            // whole archive to "unknown" and must never trigger a bomb ratio.
            if (
                $verified->isVerified
                && $entryCompressedSize > 0
                && $entryOriginalSize / $entryCompressedSize >= $this->maxRatio
            ) {
                return true;
            }

            // Only measured sizes feed the running total. A member that could not
            // be verified keeps its declared size as a hint but must never inflate
            // the total, otherwise a forged declaration could manufacture a bomb
            // verdict by itself. Verified nested archives already contributed their
            // own expansion through the shared accumulator (and report zero here).
            if ($verified->isVerified) {
                $accumulator->add($entryOriginalSize);
            }

            if ($accumulator->getTotal() >= $maxSize) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify a single central directory entry against its actual data stream.
     *
     * @param ZipSource $source The archive source
     * @param array<string, int|string> $entry The central directory entry
     * @param int $depth The recursion depth
     * @param float $maxSize The global size budget
     * @param SizeAccumulator $accumulator The shared running total
     *
     * @return EntryVerification The verification result
     *
     * @phpstan-impure
     */
    private function verifyEntry(
        ZipSource $source,
        array $entry,
        int $depth,
        float $maxSize,
        SizeAccumulator $accumulator
    ): EntryVerification {
        $declaredCompressedSize = (int)$entry['compressed_size'];
        $declaredOriginalSize = (int)$entry['original_size'];
        $localHeader = $source->readLocalHeader((int)$entry['local_header_offset']);

        if ($localHeader === null) {
            return $this->unverifiedEntry($declaredOriginalSize, $declaredCompressedSize);
        }

        if (!$this->isEntryStructurallyVerifiable($entry, $localHeader)) {
            // Encrypted members, method conflicts and unsupported methods cannot be
            // verified against the actual stream. Their declared sizes stay budget
            // hints; a clean result degrades to "unknown" instead of claiming safety.
            return $this->unverifiedEntry($declaredOriginalSize, $declaredCompressedSize);
        }

        $method = (int)$entry['method'];

        if ($declaredCompressedSize === 0 && $declaredOriginalSize === 0) {
            // A genuine empty member; the data stream must also be empty.
            $payload = $source->readPayload($localHeader, 0);

            if ($payload === null || $payload !== '') {
                return $this->unverifiedEntry($declaredOriginalSize, $declaredCompressedSize);
            }

            return EntryVerification::verified(0, 0);
        }

        $payload = $source->readPayload($localHeader, $declaredCompressedSize);

        if ($payload === null) {
            // The declared compressed size reaches past the archive (truncated).
            return $this->unverifiedEntry($declaredOriginalSize, $declaredCompressedSize);
        }

        $measurement = $this->measureEntryStream($entry, $payload, $method, $depth);

        if ($measurement->isBomb) {
            return EntryVerification::bomb(self::MAX_ENTRY_DECOMPRESSED_SIZE, $declaredCompressedSize);
        }

        if (!$measurement->isValid) {
            // Invalid or truncated deflate stream; never treat it as a bomb.
            return $this->unverifiedEntry($declaredOriginalSize, $declaredCompressedSize);
        }

        $actualOriginalSize = $measurement->originalLength;
        $actualCompressedSize = $measurement->consumed;
        $actualCrc = $measurement->crcHash;
        $decompressedData = (string)$measurement->data;

        if (!$this->matchesDeclarations($entry, $actualOriginalSize, $actualCompressedSize, $actualCrc)) {
            // The declared metadata does not match the actual, verified stream.
            return $this->unverifiedEntry($actualOriginalSize, $actualCompressedSize);
        }

        $name = (string)$entry['name'];

        if (str_ends_with(strtolower($name), '.zip')) {
            return $this->verifyNestedEntry(
                $decompressedData,
                $actualOriginalSize,
                $actualCompressedSize,
                $depth,
                $maxSize,
                $accumulator
            );
        }

        return EntryVerification::verified($actualOriginalSize, $actualCompressedSize);
    }

    /**
     * Compare the measured stream against the declared central directory metadata.
     *
     * @param array<string, int|string> $entry The central directory entry
     * @param int $actualOriginalSize The measured original size
     * @param int $actualCompressedSize The measured compressed size
     * @param string $actualCrc The measured CRC32 checksum
     *
     * @return bool Whether the declarations match the actual stream
     */
    private function matchesDeclarations(
        array $entry,
        int $actualOriginalSize,
        int $actualCompressedSize,
        string $actualCrc
    ): bool {
        return $actualOriginalSize === (int)$entry['original_size']
            && $actualCompressedSize === (int)$entry['compressed_size']
            && $this->crc32ToHex((int)$entry['crc']) === $actualCrc;
    }

    /**
     * Check whether an entry can be verified against its actual data stream.
     *
     * @param array<string, int|string> $entry The central directory entry
     * @param array<string, int> $localHeader The parsed local header
     *
     * @return bool Whether the entry structure is verifiable
     */
    private function isEntryStructurallyVerifiable(array $entry, array $localHeader): bool
    {
        $flags = (int)$entry['flags'];
        $method = (int)$entry['method'];
        $isEncrypted = ($flags & 1) === 1 || ($localHeader['flags'] & 1) === 1;

        return !$isEncrypted
            && $method === (int)$localHeader['method']
            && ($method === 0 || $method === 8);
    }

    /**
     * Measure an entry payload against its actual data stream.
     *
     * @param array<string, int|string> $entry The central directory entry
     * @param string $payload The compressed payload
     * @param int $method The compression method
     * @param int $depth The recursion depth
     *
     * @return InflationResult The measured stream
     */
    private function measureEntryStream(array $entry, string $payload, int $method, int $depth): InflationResult
    {
        if ($method === 0) {
            $length = strlen($payload);

            return new InflationResult(false, true, $length, $length, $this->crc32Hex($payload), $payload);
        }

        $retainData = $depth < self::MAX_RECURSION_DEPTH
            && str_ends_with(strtolower((string)$entry['name']), '.zip');

        // The decompression cap protects the scanner itself; it is not the bomb
        // budget. Declared sizes are only hints and must never limit inflation.
        return $this->inflatePayload($payload, self::MAX_ENTRY_DECOMPRESSED_SIZE, $retainData);
    }

    /**
     * Verify a member that carries a nested ZIP archive.
     *
     * @param string $decompressedData The verified member data
     * @param int $originalSize The measured original size
     * @param int $compressedSize The measured compressed size
     * @param int $depth The recursion depth
     * @param float $maxSize The global size budget
     * @param SizeAccumulator $accumulator The shared running total
     *
     * @return EntryVerification The verification result
     *
     * @phpstan-impure
     */
    private function verifyNestedEntry(
        string $decompressedData,
        int $originalSize,
        int $compressedSize,
        int $depth,
        float $maxSize,
        SizeAccumulator $accumulator
    ): EntryVerification {
        if ($depth >= self::MAX_RECURSION_DEPTH || $originalSize > self::MAX_NESTED_ARCHIVE_SIZE) {
            // The member itself was fully verified, but its nested content cannot
            // be inspected further. Flag inconclusive while still contributing the
            // measured size so deep nesting keeps short circuiting the budget.
            $this->unknownEntries = true;

            return EntryVerification::verified($originalSize, $compressedSize);
        }

        if (!str_starts_with($decompressedData, "PK\3\4")) {
            // A member named .zip without a ZIP signature is not a nested archive.
            return EntryVerification::verified($originalSize, $compressedSize);
        }

        $nestedIsBomb = $this->scanSource(
            new StringZipSource($decompressedData),
            $maxSize,
            $depth + 1,
            $accumulator
        );

        if ($nestedIsBomb) {
            return EntryVerification::bomb($maxSize, $compressedSize);
        }

        // The nested expansion was already added to the shared accumulator; avoid
        // counting the container bytes themselves a second time.
        return EntryVerification::verified(0, $compressedSize);
    }

    /**
     * Inflate a raw deflate payload with a hard decompression budget.
     *
     * @param string $payload The compressed payload
     * @param float $maxSize The maximum number of decompressed bytes
     * @param bool $retainData Whether to keep the decompressed bytes in memory
     *
     * @return InflationResult The inflation outcome
     */
    private function inflatePayload(string $payload, float $maxSize, bool $retainData): InflationResult
    {
        $context = inflate_init(ZLIB_ENCODING_RAW);

        if ($context === false) {
            return new InflationResult(false, false, 0, 0);
        }

        $crcContext = hash_init('crc32b');
        $data = '';
        $originalLength = 0;
        $offset = 0;
        $length = strlen($payload);
        // Small input chunks keep the temporary decompression block bounded even
        // for highly compressible bomb streams.
        $chunkSize = 8192;

        while ($offset < $length) {
            $chunk = substr($payload, $offset, $chunkSize);
            $output = inflate_add($context, $chunk, ZLIB_NO_FLUSH);

            if ($output === false) {
                return new InflationResult(false, false, $offset, $originalLength);
            }

            $outputLength = strlen($output);

            if ($originalLength + $outputLength > $maxSize) {
                // The real stream expands beyond the cap: bomb evidence.
                return new InflationResult(true, false, $offset, $originalLength + $outputLength);
            }

            hash_update($crcContext, $output);
            $originalLength += $outputLength;

            if ($retainData) {
                $data .= $output;
            }

            $offset += strlen($chunk);

            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                break;
            }
        }

        // A valid deflate member must reach the explicit stream end state.
        // Truncated input stays in ZLIB_OK and corrupted input enters an error state.
        if (inflate_get_status($context) !== ZLIB_STREAM_END) {
            return new InflationResult(false, false, $offset, $originalLength);
        }

        $consumed = (int)inflate_get_read_len($context);

        return new InflationResult(
            false,
            true,
            $consumed,
            $originalLength,
            (string)hash_final($crcContext),
            $retainData ? $data : null
        );
    }

    /**
     * Build an unverified entry result and flag the archive as inconclusive.
     *
     * @param int $originalSize The usable original size
     * @param int $compressedSize The usable compressed size
     *
     * @return EntryVerification The verification result
     *
     * @phpstan-impure
     */
    private function unverifiedEntry(int $originalSize, int $compressedSize): EntryVerification
    {
        $this->unknownEntries = true;

        return EntryVerification::unverified($originalSize, $compressedSize);
    }

    /**
     * Calculate a CRC32 checksum as an eight character hexadecimal string.
     *
     * @param string $data The data
     *
     * @return string The checksum
     */
    private function crc32Hex(string $data): string
    {
        return hash('crc32b', $data);
    }

    /**
     * Convert a central directory CRC32 integer to its hexadecimal representation.
     *
     * @param int $crc The checksum
     *
     * @return string The eight character hexadecimal checksum
     */
    private function crc32ToHex(int $crc): string
    {
        return sprintf('%08x', $crc);
    }

    /**
     * Detect file type.
     *
     * @param SplFileObject $file The file
     *
     * @return bool The status
     */
    private function isZip(SplFileObject $file): bool
    {
        $file->rewind();

        return $file->fread(4) === "PK\3\4";
    }
}

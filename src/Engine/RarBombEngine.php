<?php

namespace Selective\ArchiveBomb\Engine;

use RuntimeException;
use Selective\ArchiveBomb\Engine\Rar\Rar4HeaderParser;
use Selective\ArchiveBomb\Engine\Rar\RarEntryMetadata;
use Selective\ArchiveBomb\Scanner\BombEvidence;
use Selective\ArchiveBomb\Scanner\BombScannerResult;
use Selective\ArchiveBomb\Scanner\BombStatus;
use SplFileObject;
use Throwable;

/**
 * RAR bomb scanner.
 *
 * Instead of checking every entry against an isolated unpacked/packed ratio,
 * all entries share one total expansion budget: the sum of the unpacked entry
 * sizes is compared against the archive size multiplied by the ratio. Entries
 * that cannot be assessed (encrypted, zero packed size or damaged volume
 * boundaries) make the whole verdict unknown instead of silently passing.
 */
final class RarBombEngine implements EngineInterface
{
    /**
     * Stable identifier used as prefix for every evidence identity.
     */
    public const ENGINE_ID = 'rar';

    /**
     * @var int The maximum expansion factor of the whole archive
     */
    private int $maxRatio;

    /**
     * @var Rar4HeaderParser The strict RAR4 header parser
     */
    private Rar4HeaderParser $headerParser;

    /**
     * The constructor.
     *
     * @param int $maxRatio The max Bomb size ratio (total unpacked / packed)
     */
    public function __construct(int $maxRatio = 1000)
    {
        $this->maxRatio = $maxRatio;
        $this->headerParser = new Rar4HeaderParser();
    }

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
     * Scan for RAR bombs.
     *
     * @param SplFileObject $file The rar file
     *
     * @throws RuntimeException When the file handle is invalid
     *
     * @return BombScannerResult The result
     */
    public function scanFile(SplFileObject $file): BombScannerResult
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new RuntimeException(sprintf('File not found: %s', $file->getFilename()));
        }

        if (!$this->isRar($file)) {
            // Not a RAR file, another engine is responsible for it.
            return new BombScannerResult(BombStatus::Safe);
        }

        $contents = $this->readContents($file);

        if (!$this->isRar4($contents)) {
            // RAR5 encryption and split entries cannot be inspected reliably.
            return $this->unknownResult(
                'archive',
                'RAR5 archives cannot be assessed completely by this scanner.',
                0.0,
                0.0
            );
        }

        try {
            $entries = $this->headerParser->parse($contents);
        } catch (Throwable $exception) {
            return $this->unknownResult(
                'archive',
                sprintf('Damaged RAR volume boundary: %s', $exception->getMessage()),
                0.0,
                0.0
            );
        }

        return $this->evaluateEntries($entries, strlen($contents));
    }

    /**
     * Evaluate the parsed entries against the shared expansion budget.
     *
     * @param array<int, RarEntryMetadata> $entries The parsed entries
     * @param int $archiveSize The actual archive size in bytes
     *
     * @return BombScannerResult The result
     */
    private function evaluateEntries(array $entries, int $archiveSize): BombScannerResult
    {
        $evidence = [];
        $totalUnpackedSize = 0.0;
        $totalPackedSize = 0.0;

        foreach ($entries as $entry) {
            $entryId = $this->createEntryId($entry);

            if ($entry->crossesVolumeBoundary()) {
                $evidence[] = $this->createEvidence(
                    $entryId,
                    BombStatus::Unknown,
                    'Entry crosses a multi-volume boundary, its complete size is not available in this volume.',
                    $entry
                );

                continue;
            }

            if ($entry->isEncrypted()) {
                $evidence[] = $this->createEvidence(
                    $entryId,
                    BombStatus::Unknown,
                    'Entry is password encrypted and cannot be inspected.',
                    $entry
                );

                continue;
            }

            if ($entry->packedSize <= 0.0 && $entry->unpackedSize > 0.0) {
                $evidence[] = $this->createEvidence(
                    $entryId,
                    BombStatus::Unknown,
                    'Entry declares unpacked data but no packed size, the expansion ratio cannot be computed.',
                    $entry
                );

                continue;
            }

            $totalUnpackedSize += $entry->unpackedSize;
            $totalPackedSize += $entry->packedSize;
        }

        $hasUnknown = $evidence !== [];

        // The shared budget is based on the actual archive size so that the
        // packed payload can never exceed the bytes that physically exist.
        $referencePackedSize = max($totalPackedSize, (float)$archiveSize);
        $budget = $referencePackedSize * $this->maxRatio;

        if ($totalUnpackedSize >= $budget) {
            $evidence[] = new BombEvidence(
                $this->getId(),
                'archive#total',
                BombStatus::Bomb,
                sprintf(
                    'Total unpacked size %.0f reaches the shared expansion budget %.0f (ratio %d).',
                    $totalUnpackedSize,
                    $budget,
                    $this->maxRatio
                ),
                $referencePackedSize,
                $totalUnpackedSize
            );

            return new BombScannerResult(BombStatus::Bomb, $evidence);
        }

        if ($hasUnknown) {
            return new BombScannerResult(BombStatus::Unknown, $evidence);
        }

        return new BombScannerResult(BombStatus::Safe, $evidence);
    }

    /**
     * Create a stable entry identifier.
     *
     * @param RarEntryMetadata $entry The entry
     *
     * @return string The entry identifier
     */
    private function createEntryId(RarEntryMetadata $entry): string
    {
        $normalizedName = str_replace('\\', '/', $entry->name);

        return sprintf('entry#%d:%s', $entry->index, $normalizedName);
    }

    /**
     * Create evidence for a single entry.
     *
     * @param string $entryId The entry identifier
     * @param BombStatus $status The verdict
     * @param string $reason The reason
     * @param RarEntryMetadata $entry The entry
     *
     * @return BombEvidence The evidence
     */
    private function createEvidence(
        string $entryId,
        BombStatus $status,
        string $reason,
        RarEntryMetadata $entry
    ): BombEvidence {
        return new BombEvidence(
            $this->getId(),
            $entryId,
            $status,
            $reason,
            $entry->packedSize,
            $entry->unpackedSize
        );
    }

    /**
     * Create an unknown result for the archive itself.
     *
     * @param string $entryId The entry identifier
     * @param string $reason The reason
     * @param float $packedSize The packed size
     * @param float $unpackedSize The unpacked size
     *
     * @return BombScannerResult The result
     */
    private function unknownResult(
        string $entryId,
        string $reason,
        float $packedSize,
        float $unpackedSize
    ): BombScannerResult {
        return new BombScannerResult(
            BombStatus::Unknown,
            [
                new BombEvidence($this->getId(), $entryId, BombStatus::Unknown, $reason, $packedSize, $unpackedSize),
            ]
        );
    }

    /**
     * Read the complete file contents.
     *
     * @param SplFileObject $file The file
     *
     * @return string The contents
     */
    private function readContents(SplFileObject $file): string
    {
        $file->rewind();
        $contents = '';

        while (!$file->eof()) {
            $chunk = $file->fread(65536);

            if ($chunk === false) {
                break;
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    /**
     * Detect the RAR family from the file signature.
     *
     * @param SplFileObject $file The file
     *
     * @return bool The status
     */
    private function isRar(SplFileObject $file): bool
    {
        $file->rewind();

        return $file->fread(4) === "\x52\x61\x72\x21";
    }

    /**
     * Detect a RAR4 signature (RAR5 uses 8 bytes and ends with 01 00).
     *
     * @param string $contents The contents
     *
     * @return bool The status
     */
    private function isRar4(string $contents): bool
    {
        return str_starts_with($contents, "\x52\x61\x72\x21\x1a\x07\x00");
    }
}

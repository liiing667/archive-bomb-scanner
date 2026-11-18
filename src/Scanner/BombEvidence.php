<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * A single, engine specific finding that explains why an entry is dangerous.
 *
 * The identity is deterministic: scanning the same archive with the same
 * engine configuration always produces the same identifier, regardless of
 * the other engines that are registered.
 */
final class BombEvidence
{
    /**
     * @var string Stable identifier of the engine that produced the evidence
     */
    private string $engineId;

    /**
     * @var string Stable identifier of the entry inside the engine namespace
     */
    private string $entryId;

    /**
     * @var BombStatus The verdict for this entry
     */
    private BombStatus $status;

    /**
     * @var string Human readable explanation of the finding
     */
    private string $reason;

    /**
     * @var float The packed (compressed) size of the entry
     */
    private float $packedSize;

    /**
     * @var float The unpacked (original) size of the entry
     */
    private float $unpackedSize;

    /**
     * The constructor.
     *
     * @param string $engineId The engine identifier
     * @param string $entryId The entry identifier
     * @param BombStatus $status The entry verdict
     * @param string $reason The reason
     * @param float $packedSize The packed size
     * @param float $unpackedSize The unpacked size
     */
    public function __construct(
        string $engineId,
        string $entryId,
        BombStatus $status,
        string $reason,
        float $packedSize = 0.0,
        float $unpackedSize = 0.0
    ) {
        $this->engineId = $engineId;
        $this->entryId = $entryId;
        $this->status = $status;
        $this->reason = $reason;
        $this->packedSize = $packedSize;
        $this->unpackedSize = $unpackedSize;
    }

    /**
     * Get the engine identifier.
     *
     * @return string The engine identifier
     */
    public function getEngineId(): string
    {
        return $this->engineId;
    }

    /**
     * Get the entry identifier.
     *
     * @return string The entry identifier
     */
    public function getEntryId(): string
    {
        return $this->entryId;
    }

    /**
     * Get the deterministic, fully qualified identity of the evidence.
     *
     * @return string The evidence identity, e.g. "rar:entry#3:Desktop/10GB"
     */
    public function getIdentity(): string
    {
        return sprintf('%s:%s', $this->engineId, $this->entryId);
    }

    /**
     * Get the entry verdict.
     *
     * @return BombStatus The status
     */
    public function getStatus(): BombStatus
    {
        return $this->status;
    }

    /**
     * Get the reason.
     *
     * @return string The reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get the packed size.
     *
     * @return float The packed size
     */
    public function getPackedSize(): float
    {
        return $this->packedSize;
    }

    /**
     * Get the unpacked size.
     *
     * @return float The unpacked size
     */
    public function getUnpackedSize(): float
    {
        return $this->unpackedSize;
    }

    /**
     * Compare with another value object.
     *
     * @param BombEvidence $other The other evidence
     *
     * @return bool Status
     */
    public function equals(BombEvidence $other): bool
    {
        return $this->getIdentity() === $other->getIdentity() && $this->status === $other->status;
    }
}

<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * Scanner result value object.
 */
final class BombScannerResult
{
    /**
     * @var BombStatus The verdict
     */
    private BombStatus $status;

    /**
     * @var array<int, BombEvidence> The evidence collected from the engines
     */
    private array $evidence;

    /**
     * The constructor.
     *
     * @param BombStatus $status The verdict
     * @param array<int, BombEvidence> $evidence The evidence collected from the engines
     */
    public function __construct(BombStatus $status, array $evidence = [])
    {
        $this->status = $status;
        $this->evidence = array_values($evidence);
    }

    /**
     * Get result.
     *
     * @return bool True only when a bomb was confirmed
     */
    public function isBomb(): bool
    {
        return $this->status->isBomb();
    }

    /**
     * Get the verdict.
     *
     * @return BombStatus The status
     */
    public function getStatus(): BombStatus
    {
        return $this->status;
    }

    /**
     * Whether the input could not be assessed completely.
     *
     * @return bool True when the verdict is unknown
     */
    public function isUnknown(): bool
    {
        return $this->status === BombStatus::Unknown;
    }

    /**
     * Whether the input was inspected and is clean.
     *
     * @return bool True when the verdict is safe
     */
    public function isSafe(): bool
    {
        return $this->status === BombStatus::Safe;
    }

    /**
     * Get all collected evidence.
     *
     * @return array<int, BombEvidence> The evidence
     */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    /**
     * Get only the evidence that confirms a bomb.
     *
     * @return array<int, BombEvidence> The bomb evidence
     */
    public function getBombEvidence(): array
    {
        return $this->filterEvidenceByStatus(BombStatus::Bomb);
    }

    /**
     * Get only the evidence that prevents a final verdict.
     *
     * @return array<int, BombEvidence> The unknown evidence
     */
    public function getUnknownEvidence(): array
    {
        return $this->filterEvidenceByStatus(BombStatus::Unknown);
    }

    /**
     * Filter the evidence by status.
     *
     * @param BombStatus $status The status to filter for
     *
     * @return array<int, BombEvidence> The filtered evidence
     */
    private function filterEvidenceByStatus(BombStatus $status): array
    {
        $result = [];

        foreach ($this->evidence as $evidence) {
            if ($evidence->getStatus() === $status) {
                $result[] = $evidence;
            }
        }

        return $result;
    }

    /**
     * Compare with other value object.
     *
     * @param BombScannerResult $other The other type
     *
     * @return bool Status
     */
    public function equals(BombScannerResult $other): bool
    {
        return $this->status === $other->status;
    }
}

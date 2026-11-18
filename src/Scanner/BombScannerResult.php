<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * Scanner result value object.
 */
final class BombScannerResult
{
    /**
     * @var BombStatus The result status
     */
    private BombStatus $status;

    /**
     * ImageType constructor.
     *
     * @param bool|BombStatus $status The result status; a boolean is kept
     * for backwards compatibility and maps to safe/bomb
     */
    public function __construct(bool|BombStatus $status)
    {
        $this->status = $status instanceof BombStatus ? $status :
            ($status ? BombStatus::Bomb : BombStatus::Safe);
    }

    /**
     * Get result.
     *
     * @return bool True only when the status is bomb
     */
    public function isBomb(): bool
    {
        return $this->status === BombStatus::Bomb;
    }

    /**
     * Get the detailed status.
     *
     * @return BombStatus The status
     */
    public function getStatus(): BombStatus
    {
        return $this->status;
    }

    /**
     * Whether the file could not be evaluated, e.g. because it is
     * truncated, malformed or uses an unsupported format.
     *
     * @return bool The status
     */
    public function isUnknown(): bool
    {
        return $this->status === BombStatus::Unknown;
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

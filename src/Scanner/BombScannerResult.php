<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * Scanner result value object.
 */
final class BombScannerResult
{
    /**
     * The file was fully inspected and no bomb was found.
     */
    public const STATUS_SAFE = 'safe';

    /**
     * The file could not be fully inspected (e.g. encrypted or corrupted entries).
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * The file is an archive bomb.
     */
    public const STATUS_BOMB = 'bomb';

    /**
     * @var bool The result
     */
    private bool $isBomb;

    /**
     * @var string The detection engine name
     */
    private string $engineName;

    /**
     * @var string The reason for the result
     */
    private string $reason;

    /**
     * @var string The status: safe, unknown or bomb
     */
    private string $status;

    /**
     * ImageType constructor.
     *
     * @param bool $isBomb The result
     * @param string $engineName The detection engine name
     * @param string $reason The reason for the result
     * @param string $status The status: safe, unknown or bomb
     */
    public function __construct(
        bool $isBomb,
        string $engineName = '',
        string $reason = '',
        string $status = ''
    ) {
        $this->isBomb = $isBomb;
        $this->engineName = $engineName;
        $this->reason = $reason;
        $this->status = $status !== '' ? $status : ($isBomb ? self::STATUS_BOMB : self::STATUS_SAFE);
    }

    /**
     * Get result.
     *
     * @return bool The result
     */
    public function isBomb(): bool
    {
        return $this->isBomb;
    }

    /**
     * Get the detection engine name.
     *
     * @return string The engine name
     */
    public function getEngineName(): string
    {
        return $this->engineName;
    }

    /**
     * Get the reason for the result.
     *
     * @return string The reason
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get the status.
     *
     * @return string The status: safe, unknown or bomb
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Whether the file was fully verified as safe.
     *
     * @return bool The status
     */
    public function isSafe(): bool
    {
        return $this->status === self::STATUS_SAFE;
    }

    /**
     * Whether the file could not be fully inspected.
     *
     * @return bool The status
     */
    public function isUnknown(): bool
    {
        return $this->status === self::STATUS_UNKNOWN;
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
        return $this->isBomb === $other->isBomb;
    }
}

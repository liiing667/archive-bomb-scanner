<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * Outcome of a bounded deflate operation.
 */
final class InflationResult
{
    /**
     * @var bool Whether the stream exceeded the decompression budget (bomb evidence)
     */
    public bool $isBomb;

    /**
     * @var bool Whether the deflate stream was read to a valid end
     */
    public bool $isValid;

    /**
     * @var int The number of compressed bytes consumed
     */
    public int $consumed;

    /**
     * @var int The number of decompressed bytes produced
     */
    public int $originalLength;

    /**
     * @var string The rolling CRC32 checksum as a hex string
     */
    public string $crcHash;

    /**
     * @var string|null The decompressed data, only retained for nested archives
     */
    public ?string $data;

    /**
     * The constructor.
     *
     * @param bool $isBomb Whether the budget was exceeded
     * @param bool $isValid Whether a valid stream end was reached
     * @param int $consumed The compressed bytes consumed
     * @param int $originalLength The decompressed byte count
     * @param string $crcHash The rolling CRC32 hash
     * @param string|null $data The retained decompressed data
     */
    public function __construct(
        bool $isBomb,
        bool $isValid,
        int $consumed,
        int $originalLength,
        string $crcHash = '',
        ?string $data = null
    ) {
        $this->isBomb = $isBomb;
        $this->isValid = $isValid;
        $this->consumed = $consumed;
        $this->originalLength = $originalLength;
        $this->crcHash = $crcHash;
        $this->data = $data;
    }
}

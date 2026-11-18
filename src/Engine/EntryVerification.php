<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * Verification result for a single ZIP member.
 */
final class EntryVerification
{
    /**
     * @var bool Whether the member proves an archive bomb
     */
    public bool $isBomb;

    /**
     * @var bool Whether the member was fully verified against its data stream
     */
    public bool $isVerified;

    /**
     * @var int The verified (or best available) original size
     */
    public int $originalSize;

    /**
     * @var int The verified (or best available) compressed size
     */
    public int $compressedSize;

    /**
     * The constructor.
     *
     * @param bool $isBomb Whether the member proves an archive bomb
     * @param bool $isVerified Whether the member was fully verified
     * @param int $originalSize The original size
     * @param int $compressedSize The compressed size
     */
    private function __construct(bool $isBomb, bool $isVerified, int $originalSize, int $compressedSize)
    {
        $this->isBomb = $isBomb;
        $this->isVerified = $isVerified;
        $this->originalSize = $originalSize;
        $this->compressedSize = $compressedSize;
    }

    /**
     * Create a verified result.
     *
     * @param int $originalSize The verified original size
     * @param int $compressedSize The verified compressed size
     *
     * @return self The result
     */
    public static function verified(int $originalSize, int $compressedSize): self
    {
        return new self(false, true, $originalSize, $compressedSize);
    }

    /**
     * Create an unverified result for an encrypted, unsupported or corrupted member.
     *
     * @param int $originalSize The best available original size
     * @param int $compressedSize The best available compressed size
     *
     * @return self The result
     */
    public static function unverified(int $originalSize, int $compressedSize): self
    {
        return new self(false, false, $originalSize, $compressedSize);
    }

    /**
     * Create a result proving an archive bomb.
     *
     * @param float $originalSize The original size that exceeded the budget
     * @param int $compressedSize The compressed size
     *
     * @return self The result
     */
    public static function bomb(float $originalSize, int $compressedSize): self
    {
        return new self(true, false, (int)$originalSize, $compressedSize);
    }
}

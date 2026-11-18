<?php

namespace Selective\ArchiveBomb\Engine\Rar;

/**
 * Metadata of a single RAR4 file header, including the flags that the generic
 * RAR reader does not expose (encryption and volume boundaries).
 */
final class RarEntryMetadata
{
    public const FLAG_CONTINUED_FROM_PREVIOUS_VOLUME = 0x01;
    public const FLAG_CONTINUED_IN_NEXT_VOLUME = 0x02;
    public const FLAG_ENCRYPTED = 0x04;

    /**
     * @var int Zero based position of the entry inside the archive
     */
    public int $index = 0;

    /**
     * @var string The entry name
     */
    public string $name = '';

    /**
     * @var int The raw RAR file header flags
     */
    public int $flags = 0;

    /**
     * @var int The compression method
     */
    public int $method = 0;

    /**
     * @var float The packed (compressed) size
     */
    public float $packedSize = 0.0;

    /**
     * @var float The unpacked (original) size
     */
    public float $unpackedSize = 0.0;

    /**
     * Whether the entry continues from a previous volume.
     *
     * @return bool The status
     */
    public function continuesFromPreviousVolume(): bool
    {
        return ($this->flags & self::FLAG_CONTINUED_FROM_PREVIOUS_VOLUME) !== 0;
    }

    /**
     * Whether the entry continues in the next volume.
     *
     * @return bool The status
     */
    public function continuesInNextVolume(): bool
    {
        return ($this->flags & self::FLAG_CONTINUED_IN_NEXT_VOLUME) !== 0;
    }

    /**
     * Whether the entry is password encrypted.
     *
     * @return bool The status
     */
    public function isEncrypted(): bool
    {
        return ($this->flags & self::FLAG_ENCRYPTED) !== 0;
    }

    /**
     * Whether the entry crosses a volume boundary in any direction.
     *
     * @return bool The status
     */
    public function crossesVolumeBoundary(): bool
    {
        return $this->continuesFromPreviousVolume() || $this->continuesInNextVolume();
    }
}

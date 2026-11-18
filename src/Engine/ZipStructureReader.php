<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * Shared low level ZIP structure parsing helpers.
 */
trait ZipStructureReader
{
    /**
     * Find the end of central directory record offset.
     *
     * @param string $contents The archive contents (or its tail)
     * @param int $baseOffset The offset of the contents within the full archive
     *
     * @return int|null The absolute offset
     */
    private function findEndOfCentralDirectoryOffset(string $contents, int $baseOffset = 0): ?int
    {
        $length = strlen($contents);
        $start = max(0, $length - 65557);

        for ($offset = $length - 22; $offset >= $start; $offset--) {
            if (substr($contents, $offset, 4) === "PK\5\6") {
                return $baseOffset + $offset;
            }
        }

        return null;
    }

    /**
     * Read an unsigned little-endian short.
     *
     * @param string $contents The contents
     * @param int $offset The offset
     *
     * @return int The value
     */
    private function readUnsignedShort(string $contents, int $offset): int
    {
        return unpack('v', substr($contents, $offset, 2))[1];
    }

    /**
     * Read an unsigned little-endian long.
     *
     * @param string $contents The contents
     * @param int $offset The offset
     *
     * @return int The value
     */
    private function readUnsignedLong(string $contents, int $offset): int
    {
        return unpack('V', substr($contents, $offset, 4))[1];
    }
}

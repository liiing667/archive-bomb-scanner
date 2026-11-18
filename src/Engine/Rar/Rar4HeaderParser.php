<?php

namespace Selective\ArchiveBomb\Engine\Rar;

use RuntimeException;

/**
 * Strict RAR4 header parser.
 *
 * The parser validates every header and data boundary while walking the
 * archive and exposes the raw file header flags, which the generic RAR reader
 * hides, so that encrypted entries and entries crossing volume boundaries can
 * be handled explicitly.
 */
final class Rar4HeaderParser
{
    private const SIGNATURE = "\x52\x61\x72\x21\x1a\x07\x00";

    private const TYPE_FILE = 0x74;
    private const TYPE_END_ARCHIVE = 0x7b;

    private const FLAG_HAS_ADD_SIZE = 0x8000;
    private const FLAG_HIGH_SIZES = 0x0100;

    private const KNOWN_HEADER_TYPES = [
        0x72, // marker
        0x73, // main archive header
        0x74, // file header
        0x75, // old style comment header
        0x76, // AV header
        0x77, // sub-block
        0x78, // recovery record
        0x79, // authentication info
        0x7a, // sub-block
        0x7b, // end of archive
    ];

    /**
     * Parse all file headers of a RAR4 archive.
     *
     * @param string $contents The complete archive contents
     *
     * @throws RuntimeException When a header or a volume boundary is damaged
     *
     * @return array<int, RarEntryMetadata> The parsed entry metadata
     */
    public function parse(string $contents): array
    {
        if (!str_starts_with($contents, self::SIGNATURE)) {
            throw new RuntimeException('Invalid RAR4 signature.');
        }

        $length = strlen($contents);
        $offset = strlen(self::SIGNATURE);
        $entries = [];
        $index = 0;

        while ($offset < $length) {
            $block = $this->readBlockHeader($contents, $offset, $length);
            $type = $block['type'];
            $nextBlockOffset = $block['next_offset'];

            if ($type === self::TYPE_FILE) {
                $entries[] = $this->parseFileHeader(
                    $contents,
                    $offset,
                    $block['header_size'],
                    $block['add_size_field_length'],
                    $block['data_size'],
                    $index
                );
                $index++;
                $offset = $this->resolveNextOffset($contents, $nextBlockOffset, $length);
            } else {
                $offset = $nextBlockOffset;
            }

            if ($type === self::TYPE_END_ARCHIVE) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Read and validate the common part of one RAR4 volume header.
     *
     * @param string $contents The archive contents
     * @param int $offset The header offset
     * @param int $length The archive length
     *
     * @throws RuntimeException When the header or its boundary is damaged
     *
     * @return array{
     *     type: int,
     *     header_size: int,
     *     data_size: int,
     *     add_size_field_length: int,
     *     next_offset: int
     * } The block description
     */
    private function readBlockHeader(string $contents, int $offset, int $length): array
    {
        if ($length < $offset + 7) {
            throw new RuntimeException(sprintf('Truncated RAR4 volume header at offset %d.', $offset));
        }

        $type = ord($contents[$offset + 2]);
        $flags = $this->readUnsignedShort($contents, $offset + 3);
        $headerSize = $this->readUnsignedShort($contents, $offset + 5);

        if (!in_array($type, self::KNOWN_HEADER_TYPES, true)) {
            throw new RuntimeException(
                sprintf('Unknown RAR4 header type 0x%02X at offset %d.', $type, $offset)
            );
        }

        if ($headerSize < 7 || $length < $offset + $headerSize) {
            throw new RuntimeException(
                sprintf('RAR4 header body exceeds the archive boundary at offset %d.', $offset)
            );
        }

        $dataSize = 0;
        $addSizeFieldLength = 0;
        if (($flags & self::FLAG_HAS_ADD_SIZE) !== 0) {
            $dataSize = $this->readUnsignedLong($contents, $offset + 7);
            $addSizeFieldLength = 4;
        }

        // The declared HEAD_SIZE already includes the optional add size
        // field. The packed data area follows the complete header, so the
        // next block starts after header size plus packed data size.
        $nextBlockOffset = $offset + $headerSize + $dataSize;

        if ($nextBlockOffset > $length) {
            throw new RuntimeException(
                sprintf('Truncated RAR4 block at offset %d: data exceeds the archive boundary.', $offset)
            );
        }

        return [
            'type' => $type,
            'header_size' => $headerSize,
            'data_size' => $dataSize,
            'add_size_field_length' => $addSizeFieldLength,
            'next_offset' => $nextBlockOffset,
        ];
    }

    /**
     * Resolve the offset of the block following a file header.
     *
     * Real world writers may embed the ENDARC header in the last bytes of the
     * final packed data area. Its type byte is then located two bytes before
     * the computed next block offset.
     *
     * @param string $contents The archive contents
     * @param int $nextBlockOffset The offset after the packed data area
     * @param int $length The archive length
     *
     * @return int The next block offset
     */
    private function resolveNextOffset(string $contents, int $nextBlockOffset, int $length): int
    {
        $embeddedTypeOffset = $nextBlockOffset - 2;

        if ($this->isEmbeddedEndArchive($contents, $embeddedTypeOffset, $length)) {
            return $embeddedTypeOffset - 2;
        }

        return $nextBlockOffset;
    }

    /**
     * Check whether an embedded ENDARC header starts at the given offset.
     *
     * @param string $contents The archive contents
     * @param int $typeOffset The candidate ENDARC type byte offset
     * @param int $length The archive length
     *
     * @return bool True when a complete ENDARC header is present
     */
    private function isEmbeddedEndArchive(string $contents, int $typeOffset, int $length): bool
    {
        $offset = $typeOffset - 2;

        if ($offset < 0 || $length < $offset + 7) {
            return false;
        }

        $type = ord($contents[$offset + 2]);
        $flags = $this->readUnsignedShort($contents, $offset + 3);
        $headerSize = $this->readUnsignedShort($contents, $offset + 5);

        // A genuine embedded ENDARC is always the final block of the file,
        // so it must reach exactly to the end of the archive. This prevents
        // ordinary packed data that contains the ENDARC type byte from
        // triggering the detection on a non-final entry.
        return $type === self::TYPE_END_ARCHIVE
            && ($flags & self::FLAG_HAS_ADD_SIZE) === 0
            && $headerSize === 7
            && $offset + 7 === $length;
    }

    /**
     * Parse a RAR4 file header.
     *
     * The file header body starts after the 7 byte common volume header and
     * the optional 4 byte add size field and has this layout:
     * UNP_SIZE(4) HOST_OS(1) FILE_CRC(4) FTIME(4) UNP_VER(1) METHOD(1)
     * NAME_SIZE(2) ATTR(4) [HIGH_PACK(4) HIGH_UNP(4)] NAME(NAME_SIZE).
     * The packed size is only carried by the common header add size field.
     *
     * @param string $contents The archive contents
     * @param int $headerStart The common header offset
     * @param int $headerSize The declared total header size
     * @param int $addSizeFieldLength The length of the common add size field
     * @param int $dataSize The declared packed data size
     * @param int $index The entry index
     *
     * @throws RuntimeException When the file header is damaged
     *
     * @return RarEntryMetadata The entry metadata
     */
    private function parseFileHeader(
        string $contents,
        int $headerStart,
        int $headerSize,
        int $addSizeFieldLength,
        int $dataSize,
        int $index
    ): RarEntryMetadata {
        $flags = $this->readUnsignedShort($contents, $headerStart + 3);
        $bodyOffset = $headerStart + 7 + $addSizeFieldLength;
        // HEAD_SIZE covers the common header (including the add size field)
        // and the type specific body, so it marks the end of the body.
        $bodyEnd = $headerStart + $headerSize;

        $unpackedSizeLow = $this->readUnsignedLong($contents, $bodyOffset);
        $method = ord($contents[$bodyOffset + 14]);
        $nameSize = $this->readUnsignedShort($contents, $bodyOffset + 15);

        $cursor = $bodyOffset + 21;

        $highUnpackedSize = 0;

        if (($flags & self::FLAG_HIGH_SIZES) !== 0) {
            if ($cursor + 8 > $bodyEnd) {
                throw new RuntimeException(
                    sprintf('Damaged RAR4 high size fields for entry #%d at offset %d.', $index, $headerStart)
                );
            }

            // High packed size (ignored, the add size is authoritative) and
            // high unpacked size, 4 bytes each.
            $highUnpackedSize = $this->readUnsignedLong($contents, $cursor + 4);
            $cursor += 8;
        }

        if ($nameSize < 0 || $cursor + $nameSize > $bodyEnd) {
            throw new RuntimeException(
                sprintf('RAR4 entry name exceeds the header boundary at offset %d.', $headerStart)
            );
        }

        $name = substr($contents, $cursor, $nameSize);

        $entry = new RarEntryMetadata();
        $entry->index = $index;
        $entry->name = $name;
        $entry->flags = $flags;
        $entry->method = $method;
        // The common header add size is the authoritative packed size.
        $entry->packedSize = (float)$dataSize;
        $entry->unpackedSize = $this->toInt64($highUnpackedSize, $unpackedSizeLow);

        return $entry;
    }

    /**
     * Combine the high and low 32 bit parts into a 64 bit value.
     *
     * @param int $high The high part
     * @param int $low The low part
     *
     * @return float The 64 bit value
     */
    private function toInt64(int $high, int $low): float
    {
        return ($high * 4294967296.0) + $low;
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

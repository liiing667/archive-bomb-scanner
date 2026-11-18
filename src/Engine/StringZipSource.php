<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * In-memory ZIP archive source.
 */
final class StringZipSource implements ZipSource
{
    use ZipStructureReader;

    /**
     * @var string The archive contents
     */
    private string $contents;

    /**
     * The constructor.
     *
     * @param string $contents The archive contents
     */
    public function __construct(string $contents)
    {
        $this->contents = $contents;
    }

    /**
     * Get the total archive length in bytes.
     *
     * @return int The length
     */
    public function getLength(): int
    {
        return strlen($this->contents);
    }

    /**
     * Read the central directory entries.
     *
     * @return array<int, array<string, int|string>>|null The entries or null when corrupted
     */
    public function readCentralDirectoryEntries(): ?array
    {
        $contents = $this->contents;
        $eocdOffset = $this->findEndOfCentralDirectoryOffset($contents);

        if ($eocdOffset === null || strlen($contents) < $eocdOffset + 22) {
            return null;
        }

        $entryCount = $this->readUnsignedShort($contents, $eocdOffset + 10);
        $centralDirectoryOffset = $this->readUnsignedLong($contents, $eocdOffset + 16);
        $entries = [];
        $offset = $centralDirectoryOffset;

        for ($index = 0; $index < $entryCount; $index++) {
            $entry = $this->readCentralDirectoryEntry($contents, $offset);

            if ($entry === null) {
                return null;
            }

            $entries[] = $entry;
            $offset += 46 + (int)$entry['name_length'] + (int)$entry['extra_length'] +
                (int)$entry['comment_length'];
        }

        return $entries;
    }

    /**
     * Read a central directory entry.
     *
     * @param string $contents The archive contents
     * @param int $offset The central directory entry offset
     *
     * @return array<string, int|string>|null The entry or null when corrupted
     */
    private function readCentralDirectoryEntry(string $contents, int $offset): ?array
    {
        if (strlen($contents) < $offset + 46 || substr($contents, $offset, 4) !== "PK\1\2") {
            return null;
        }

        $nameLength = $this->readUnsignedShort($contents, $offset + 28);
        $extraLength = $this->readUnsignedShort($contents, $offset + 30);
        $commentLength = $this->readUnsignedShort($contents, $offset + 32);
        $nameOffset = $offset + 46;

        if (strlen($contents) < $nameOffset + $nameLength + $extraLength + $commentLength) {
            return null;
        }

        return [
            'flags' => $this->readUnsignedShort($contents, $offset + 8),
            'method' => $this->readUnsignedShort($contents, $offset + 10),
            'crc' => $this->readUnsignedLong($contents, $offset + 16),
            'compressed_size' => $this->readUnsignedLong($contents, $offset + 20),
            'original_size' => $this->readUnsignedLong($contents, $offset + 24),
            'name_length' => $nameLength,
            'extra_length' => $extraLength,
            'comment_length' => $commentLength,
            'local_header_offset' => $this->readUnsignedLong($contents, $offset + 42),
            'name' => substr($contents, $nameOffset, $nameLength),
        ];
    }

    /**
     * Read and parse a local file header.
     *
     * @param int $offset The local header offset
     *
     * @return array<string, int>|null The local header or null when corrupted
     */
    public function readLocalHeader(int $offset): ?array
    {
        $contents = $this->contents;

        if (strlen($contents) < $offset + 30 || substr($contents, $offset, 4) !== "PK\3\4") {
            return null;
        }

        $nameLength = $this->readUnsignedShort($contents, $offset + 26);
        $extraLength = $this->readUnsignedShort($contents, $offset + 28);
        $payloadOffset = $offset + 30 + $nameLength + $extraLength;

        if (strlen($contents) < $payloadOffset) {
            return null;
        }

        return [
            'flags' => $this->readUnsignedShort($contents, $offset + 6),
            'method' => $this->readUnsignedShort($contents, $offset + 8),
            'payload_offset' => $payloadOffset,
        ];
    }

    /**
     * Read the compressed payload of a local file header.
     *
     * @param array<string, int> $localHeader The parsed local header
     * @param int $declaredCompressedSize The central directory compressed size
     *
     * @return string|null The payload or null when the archive is truncated
     */
    public function readPayload(array $localHeader, int $declaredCompressedSize): ?string
    {
        $payloadOffset = (int)$localHeader['payload_offset'];

        if (strlen($this->contents) < $payloadOffset + $declaredCompressedSize) {
            return null;
        }

        return substr($this->contents, $payloadOffset, $declaredCompressedSize);
    }
}

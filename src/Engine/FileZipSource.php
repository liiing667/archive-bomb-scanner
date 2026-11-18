<?php

namespace Selective\ArchiveBomb\Engine;

use SplFileObject;

/**
 * File based ZIP archive source.
 */
final class FileZipSource implements ZipSource
{
    use ZipStructureReader;

    /**
     * @var SplFileObject The zip file
     */
    private SplFileObject $file;

    /**
     * The constructor.
     *
     * @param SplFileObject $file The zip file
     */
    public function __construct(SplFileObject $file)
    {
        $this->file = $file;
    }

    /**
     * Get the total archive length in bytes.
     *
     * @return int The length
     */
    public function getLength(): int
    {
        return (int)$this->file->getSize();
    }

    /**
     * Read the central directory entries.
     *
     * @return array<int, array<string, int|string>>|null The entries or null when corrupted
     */
    public function readCentralDirectoryEntries(): ?array
    {
        $fileSize = $this->getLength();

        if ($fileSize <= 0) {
            return null;
        }

        $tailSize = min($fileSize, 65557);
        $tailOffset = $fileSize - $tailSize;
        $tail = $this->readBytes($tailOffset, $tailSize);
        $eocdOffset = $this->findEndOfCentralDirectoryOffset($tail, $tailOffset);

        if ($eocdOffset === null || $fileSize < $eocdOffset + 22) {
            return null;
        }

        $eocd = $this->readBytes($eocdOffset, 22);
        $entryCount = $this->readUnsignedShort($eocd, 10);
        $centralDirectoryOffset = $this->readUnsignedLong($eocd, 16);
        $entries = [];
        $offset = $centralDirectoryOffset;

        for ($index = 0; $index < $entryCount; $index++) {
            $entry = $this->readCentralDirectoryEntry($offset);

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
     * @param int $offset The central directory entry offset
     *
     * @return array<string, int|string>|null The entry or null when corrupted
     */
    private function readCentralDirectoryEntry(int $offset): ?array
    {
        $header = $this->readBytes($offset, 46);

        if (strlen($header) < 46 || substr($header, 0, 4) !== "PK\1\2") {
            return null;
        }

        $nameLength = $this->readUnsignedShort($header, 28);
        $extraLength = $this->readUnsignedShort($header, 30);
        $commentLength = $this->readUnsignedShort($header, 32);
        $name = $this->readBytes($offset + 46, $nameLength);

        if (strlen($name) < $nameLength) {
            return null;
        }

        return [
            'flags' => $this->readUnsignedShort($header, 8),
            'method' => $this->readUnsignedShort($header, 10),
            'crc' => $this->readUnsignedLong($header, 16),
            'compressed_size' => $this->readUnsignedLong($header, 20),
            'original_size' => $this->readUnsignedLong($header, 24),
            'name_length' => $nameLength,
            'extra_length' => $extraLength,
            'comment_length' => $commentLength,
            'local_header_offset' => $this->readUnsignedLong($header, 42),
            'name' => $name,
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
        $header = $this->readBytes($offset, 30);

        if (strlen($header) < 30 || substr($header, 0, 4) !== "PK\3\4") {
            return null;
        }

        $nameLength = $this->readUnsignedShort($header, 26);
        $extraLength = $this->readUnsignedShort($header, 28);
        $payloadOffset = $offset + 30 + $nameLength + $extraLength;

        if ($this->getLength() < $payloadOffset) {
            return null;
        }

        return [
            'flags' => $this->readUnsignedShort($header, 6),
            'method' => $this->readUnsignedShort($header, 8),
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
        $payload = $this->readBytes($payloadOffset, $declaredCompressedSize);

        if (strlen($payload) < $declaredCompressedSize) {
            return null;
        }

        return $payload;
    }

    /**
     * Read bytes from the file.
     *
     * @param int $offset The offset
     * @param int $length The length
     *
     * @return string The bytes
     */
    private function readBytes(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $this->file->fseek($offset);

        $contents = $this->file->fread($length);

        return is_string($contents) ? $contents : '';
    }
}

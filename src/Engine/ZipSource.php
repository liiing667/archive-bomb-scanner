<?php

namespace Selective\ArchiveBomb\Engine;

/**
 * Read access to a ZIP archive, abstracting file based and in-memory sources.
 */
interface ZipSource
{
    /**
     * Get the total archive length in bytes.
     *
     * @return int The length
     */
    public function getLength(): int;

    /**
     * Read the central directory entries.
     *
     * @return array<int, array<string, int|string>>|null The entries or null when corrupted
     */
    public function readCentralDirectoryEntries(): ?array;

    /**
     * Read and parse a local file header.
     *
     * @param int $offset The local header offset
     *
     * @return array<string, int>|null The local header or null when corrupted
     */
    public function readLocalHeader(int $offset): ?array;

    /**
     * Read the compressed payload of a local file header.
     *
     * @param array<string, int> $localHeader The parsed local header
     * @param int $declaredCompressedSize The central directory compressed size
     *
     * @return string|null The payload or null when the archive is truncated
     */
    public function readPayload(array $localHeader, int $declaredCompressedSize): ?string;
}

<?php

namespace Selective\ArchiveBomb\Test\Fixture;

/**
 * Builds minimal but structurally valid RAR4 archives byte by byte.
 *
 * The archives contain real RAR4 headers so that the production parser walks
 * the exact same code paths as with real world files. Only the payloads are
 * synthetic, because the scanner only evaluates header metadata.
 */
final class Rar4ArchiveBuilder
{
    private const SIGNATURE = "\x52\x61\x72\x21\x1a\x07\x00";

    /**
     * @var array<int, array<string, mixed>> The file headers to emit
     */
    private array $files = [];

    /**
     * @var bool Whether the main header declares a multi-volume set
     */
    private bool $multiVolume = false;

    /**
     * Declare the archive as part of a multi-volume set.
     *
     * @return self The builder
     */
    public function makeMultiVolume(): self
    {
        $this->multiVolume = true;

        return $this;
    }

    /**
     * Add a file entry.
     *
     * @param string $name The entry name
     * @param int $packedSize The packed size
     * @param int $unpackedSize The unpacked size
     * @param int $flags The RAR4 file header flags
     * @param string $payload The packed payload
     *
     * @return self The builder
     */
    public function addFile(
        string $name,
        int $packedSize,
        int $unpackedSize,
        int $flags = 0,
        string $payload = ''
    ): self {
        $this->files[] = [
            'name' => $name,
            'packed_size' => $packedSize,
            'unpacked_size' => $unpackedSize,
            'flags' => $flags,
            'payload' => $payload,
        ];

        return $this;
    }

    /**
     * Render the archive as a binary string.
     *
     * @return string The archive contents
     */
    public function build(): string
    {
        $contents = self::SIGNATURE;
        $contents .= $this->buildMainHeader();

        foreach ($this->files as $file) {
            $contents .= $this->buildFileHeader($file);
        }

        $contents .= $this->buildEndHeader();

        return $contents;
    }

    /**
     * Build the main archive header.
     *
     * @return string The header bytes
     */
    private function buildMainHeader(): string
    {
        // Main header body: high position (2), position (4), encryption version (1).
        $body = pack('vVC', 0, 0, 0);
        // 0x0001 marks the archive as a volume of a multi-volume set.
        $flags = $this->multiVolume ? 0x0001 : 0x0000;

        return $this->buildVolumeHeader(0x73, $flags, $body, '');
    }

    /**
     * Build a file header including its packed data area.
     *
     * @param array<string, mixed> $file The file specification
     *
     * @return string The header and payload bytes
     */
    private function buildFileHeader(array $file): string
    {
        $name = (string)$file['name'];
        $packedSize = (int)$file['packed_size'];
        $unpackedSize = (int)$file['unpacked_size'];
        $flags = (int)$file['flags'];
        $payload = (string)$file['payload'];

        // The header body follows the RAR4 FILE_HEAD order and starts with the
        // unpacked size; PACK_SIZE only lives in the common header add size.
        $body = pack('V', $unpackedSize);
        $body .= pack('C', 0); // host OS
        $body .= str_repeat("\x00", 4); // CRC
        $body .= pack('v', 0); // DOS time
        $body .= pack('v', 0); // DOS date
        $body .= pack('C', 48); // unpack version
        $body .= pack('C', 48); // method
        $body .= pack('v', strlen($name)); // name size
        $body .= pack('V', 0); // attributes
        $body .= $name;

        // Every block with a data area must set the high flag and repeats
        // PACK_SIZE in the common header add size field.
        $flags |= 0x8000;

        // The data area must match the declared packed size exactly, otherwise
        // a reader that jumps from block to block lands on the wrong header.
        $payload = str_pad(substr($payload, 0, $packedSize), $packedSize, "\x00");

        return $this->buildVolumeHeader(0x74, $flags, $body, $payload);
    }

    /**
     * Build the end of archive header.
     *
     * @return string The header bytes
     */
    private function buildEndHeader(): string
    {
        return $this->buildVolumeHeader(0x7b, 0x0000, '', '');
    }

    /**
     * Build a common RAR4 volume header.
     *
     * @param int $type The header type
     * @param int $flags The header flags
     * @param string $body The type specific header body
     * @param string $data The packed data area
     *
     * @return string The complete block
     */
    private function buildVolumeHeader(int $type, int $flags, string $body, string $data): string
    {
        // The RAR4 HEAD_SIZE covers the 7 byte common header, the optional 4
        // byte add size field and the type specific header body.
        $hasAddSize = ($flags & 0x8000) !== 0;
        $headerSize = 7 + ($hasAddSize ? 4 : 0) + strlen($body);

        // The header CRC is not validated by the scanner and kept deterministic.
        // Layout: CRC(2) type(1) flags(2, little endian) header size(2) [add size(4)].
        $header = pack('vCv2', 0, $type, $flags, $headerSize);

        if ($hasAddSize) {
            $header .= pack('V', strlen($data));
        }

        return $header . $body . $data;
    }
}

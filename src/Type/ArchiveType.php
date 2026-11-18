<?php

namespace Selective\ArchiveBomb\Type;

/**
 * Archive format type, identified by content magic numbers instead of the
 * file extension.
 */
final class ArchiveType
{
    public const ZIP = 'zip';
    public const RAR = 'rar';
    public const GZIP = 'gzip';
    public const BZIP2 = 'bzip2';
    public const SEVEN_ZIP = '7z';
    public const XZ = 'xz';
    public const UNKNOWN = 'unknown';

    /**
     * @var string The format identifier
     */
    private string $id;

    /**
     * @var bool Whether the format is a container that may hold nested files
     */
    private bool $container;

    /**
     * The constructor.
     *
     * @param string $id The format identifier
     * @param bool $container Whether the format is a container
     */
    public function __construct(string $id, bool $container = false)
    {
        $this->id = $id;
        $this->container = $container;
    }

    /**
     * Create a ZIP archive type.
     *
     * @return self The type
     */
    public static function zip(): self
    {
        return new self(self::ZIP, true);
    }

    /**
     * Create a RAR archive type.
     *
     * @return self The type
     */
    public static function rar(): self
    {
        return new self(self::RAR, true);
    }

    /**
     * Create a GZIP archive type.
     *
     * @return self The type
     */
    public static function gzip(): self
    {
        return new self(self::GZIP, false);
    }

    /**
     * Create a BZIP2 archive type.
     *
     * @return self The type
     */
    public static function bzip2(): self
    {
        return new self(self::BZIP2, false);
    }

    /**
     * Create a 7-Zip archive type.
     *
     * @return self The type
     */
    public static function sevenZip(): self
    {
        return new self(self::SEVEN_ZIP, true);
    }

    /**
     * Create an XZ archive type.
     *
     * @return self The type
     */
    public static function xz(): self
    {
        return new self(self::XZ, false);
    }

    /**
     * Create an unknown type.
     *
     * @return self The type
     */
    public static function unknown(): self
    {
        return new self(self::UNKNOWN, false);
    }

    /**
     * Get the format identifier.
     *
     * @return string The identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Whether the format is a container that may hold nested files.
     *
     * @return bool The status
     */
    public function isContainer(): bool
    {
        return $this->container;
    }

    /**
     * Whether the format could not be identified.
     *
     * @return bool The status
     */
    public function isUnknown(): bool
    {
        return $this->id === self::UNKNOWN;
    }

    /**
     * Compare with other value object.
     *
     * @param ArchiveType $other The other type
     *
     * @return bool Status
     */
    public function equals(ArchiveType $other): bool
    {
        return $this->id === $other->id && $this->container === $other->container;
    }
}

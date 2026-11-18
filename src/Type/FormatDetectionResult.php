<?php

namespace Selective\ArchiveBomb\Type;

/**
 * Result of a content based format detection.
 */
final class FormatDetectionResult
{
    /**
     * @var ArchiveType The detected format
     */
    private ArchiveType $type;

    /**
     * @var bool Whether the content starts with a known signature but is truncated
     */
    private bool $truncated;

    /**
     * The constructor.
     *
     * @param ArchiveType $type The detected format
     * @param bool $truncated Whether the content is truncated
     */
    public function __construct(ArchiveType $type, bool $truncated = false)
    {
        $this->type = $type;
        $this->truncated = $truncated;
    }

    /**
     * Get the detected format.
     *
     * @return ArchiveType The type
     */
    public function getType(): ArchiveType
    {
        return $this->type;
    }

    /**
     * Whether the content starts with a known signature but misses required
     * structural records, e.g. a ZIP without end of central directory record.
     *
     * @return bool The status
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    /**
     * Compare with other value object.
     *
     * @param FormatDetectionResult $other The other result
     *
     * @return bool Status
     */
    public function equals(FormatDetectionResult $other): bool
    {
        return $this->truncated === $other->truncated && $this->type->equals($other->type);
    }
}

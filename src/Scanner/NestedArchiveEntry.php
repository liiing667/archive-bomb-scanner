<?php

namespace Selective\ArchiveBomb\Scanner;

use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\ScanStatus;

/**
 * A member encountered while scanning a container. The container id keeps the
 * association with the outer upload even when the member extension is faked.
 */
final class NestedArchiveEntry
{
    /**
     * @var string The id of the container holding this member
     */
    private string $containerId;

    /**
     * @var string The member name inside the container
     */
    private string $name;

    /**
     * @var ArchiveType The content based format of the member
     */
    private ArchiveType $type;

    /**
     * @var ScanStatus The explicit scanning status
     */
    private ScanStatus $status;

    /**
     * The constructor.
     *
     * @param string $containerId The container id
     * @param string $name The member name
     * @param ArchiveType $type The content based format
     * @param ScanStatus $status The scanning status
     */
    public function __construct(string $containerId, string $name, ArchiveType $type, ScanStatus $status)
    {
        $this->containerId = $containerId;
        $this->name = $name;
        $this->type = $type;
        $this->status = $status;
    }

    /**
     * Get the container id.
     *
     * @return string The id
     */
    public function getContainerId(): string
    {
        return $this->containerId;
    }

    /**
     * Get the member name.
     *
     * @return string The name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the content based format.
     *
     * @return ArchiveType The type
     */
    public function getType(): ArchiveType
    {
        return $this->type;
    }

    /**
     * Get the scanning status.
     *
     * @return ScanStatus The status
     */
    public function getStatus(): ScanStatus
    {
        return $this->status;
    }
}

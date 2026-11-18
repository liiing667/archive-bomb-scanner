<?php

namespace Selective\ArchiveBomb\Scanner;

use Selective\ArchiveBomb\Type\ArchiveType;
use Selective\ArchiveBomb\Type\ScanStatus;

/**
 * Scanner result value object.
 */
final class BombScannerResult
{
    /**
     * @var bool The result
     */
    private bool $isBomb;

    /**
     * @var string The id of the scanned container
     */
    private string $containerId;

    /**
     * @var ArchiveType The content based format of the scanned file
     */
    private ArchiveType $type;

    /**
     * @var ScanStatus The explicit scanning status
     */
    private ScanStatus $status;

    /**
     * @var array<int, NestedArchiveEntry> The nested container members
     */
    private array $nestedEntries = [];

    /**
     * ImageType constructor.
     *
     * @param bool $isBomb The result
     * @param string $containerId The container id
     * @param ArchiveType|null $type The content based format
     * @param ScanStatus|null $status The scanning status
     */
    public function __construct(
        bool $isBomb,
        string $containerId = '',
        ?ArchiveType $type = null,
        ?ScanStatus $status = null
    ) {
        $this->isBomb = $isBomb;
        $this->containerId = $containerId;
        $this->type = $type ?? ArchiveType::unknown();
        $this->status = $status ?? ScanStatus::RegularFile;
    }

    /**
     * Get result.
     *
     * @return bool The result
     */
    public function isBomb(): bool
    {
        return $this->isBomb;
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
     * Get the content based format.
     *
     * @return ArchiveType The type
     */
    public function getType(): ArchiveType
    {
        return $this->type;
    }

    /**
     * Get the explicit scanning status.
     *
     * @return ScanStatus The status
     */
    public function getStatus(): ScanStatus
    {
        return $this->status;
    }

    /**
     * Add a nested container member.
     *
     * @param NestedArchiveEntry $entry The member
     *
     * @return void
     */
    public function addNestedEntry(NestedArchiveEntry $entry): void
    {
        $this->nestedEntries[] = $entry;
    }

    /**
     * Replace the nested container members.
     *
     * @param array<int, NestedArchiveEntry> $entries The members
     *
     * @return void
     */
    public function setNestedEntries(array $entries): void
    {
        $this->nestedEntries = $entries;
    }

    /**
     * Get the nested container members.
     *
     * @return array<int, NestedArchiveEntry> The members
     */
    public function getNestedEntries(): array
    {
        return $this->nestedEntries;
    }

    /**
     * Compare with other value object.
     *
     * @param BombScannerResult $other The other type
     *
     * @return bool Status
     */
    public function equals(BombScannerResult $other): bool
    {
        return $this->isBomb === $other->isBomb;
    }
}

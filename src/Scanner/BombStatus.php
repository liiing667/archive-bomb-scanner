<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * The verdict of a single engine or the whole scanner.
 */
enum BombStatus: string
{
    /**
     * A bomb pattern was detected with hard evidence.
     */
    case Bomb = 'bomb';

    /**
     * The input could not be assessed completely, e.g. encrypted or damaged data.
     */
    case Unknown = 'unknown';

    /**
     * The input was inspected and no bomb pattern was found.
     */
    case Safe = 'safe';

    /**
     * Whether the status represents a confirmed bomb.
     *
     * @return bool The status
     */
    public function isBomb(): bool
    {
        return $this === self::Bomb;
    }
}

<?php

namespace Selective\ArchiveBomb\Scanner;

/**
 * Scanner result status.
 */
enum BombStatus: string
{
    /**
     * The file could be evaluated and is considered safe.
     */
    case Safe = 'safe';

    /**
     * The file could be evaluated and is considered a bomb.
     */
    case Bomb = 'bomb';

    /**
     * The file could not be evaluated, e.g. it is truncated, malformed
     * or uses an unsupported format. It must not be treated as safe.
     */
    case Unknown = 'unknown';
}

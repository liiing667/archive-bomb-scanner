<?php

namespace Selective\ArchiveBomb\Type;

/**
 * Explicit scanning status for a scanned file or nested container member.
 */
enum ScanStatus: string
{
    /**
     * A regular file that is not an archive.
     */
    case RegularFile = 'regular_file';

    /**
     * A supported archive container that was scanned successfully.
     */
    case ArchiveScanned = 'archive_scanned';

    /**
     * A container whose magic number does not form a complete archive,
     * e.g. a truncated ZIP without an end of central directory record.
     */
    case Truncated = 'truncated';

    /**
     * A container with a recognised signature that the scanner cannot parse.
     */
    case UnsupportedArchive = 'unsupported_archive';

    /**
     * A member with an unknown or unrecognised content format.
     */
    case UnknownFormat = 'unknown_format';
}
